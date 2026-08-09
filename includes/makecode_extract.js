/**
 * MakeCode Block Extractor — JavaScript Port
 * ============================================
 * Port exact de makecode_extract.py — aucune dépendance externe.
 * Toutes les primitives OpenCV/NumPy sont réimplémentées en pur JS.
 *
 * Usage:
 *   const result = MKExtract.extract(imageData);
 *   // imageData = canvas.getContext('2d').getImageData(0, 0, w, h)
 *   // result = { manifest, blockMasks, bgColor, phases }
 */
const MKExtract = (function () {
  'use strict';

  // ═══════════════════════════════════════════════════════════════════
  // CONSTANTES (identiques au Python)
  // ═══════════════════════════════════════════════════════════════════
  const CONTAINER_HEIGHT_THRESHOLD = 80;
  const FIELD_HEIGHT_THRESHOLD = 40;
  const DIAMOND_MIN_WIDTH = 60;
  // Au-delà de cette hauteur, un enfant n'est plus un champ MakeCode (menu, ovale, nombre :
  // 20 à 32 px sur les captures) mais un bloc-instruction (38 px et plus, même sans champ).
  // Un tel enfant n'est absorbé par son parent que s'il en a la couleur — cf. phase 4j.
  const STATEMENT_MIN_HEIGHT = 34;
  const MIN_REGION_AREA = 15;
  const SMALL_FRAGMENT_AREA = 1500;
  const BORDER_ASSIGN_DIST = 2.5;
  const HUE_MERGE_THRESHOLD = 12;
  const VAL_MERGE_THRESHOLD = 35;
  const NOTCH_DIST_THRESHOLD = 4.0;
  const NOTCH_HUE_THRESHOLD = 30;
  const TIGHT_HUE_THRESHOLD = 10;
  const EXTRACTION_PAD = 3;
  // Distance RGB en dessous de laquelle deux régions ont la MÊME couleur de
  // remplissage : les blocs Blockly/MakeCode sont en aplats, deux morceaux d'un
  // même bloc ont donc une moyenne quasi identique, alors que deux catégories
  // différentes sont séparées d'au moins ~40.
  const SAME_FILL_DIST = 20;

  // ═══════════════════════════════════════════════════════════════════
  // DÉTECTION SOURCE : Scratch vs MakeCode
  // ═══════════════════════════════════════════════════════════════════
  //
  // Scratch :  fond blanc (>245), teinte dominante ~17° (orange)
  // MakeCode : fond gris chaud (~230), teinte dominante ~90° (teal)
  //
  function detectSource(rgba, w, h) {
    const n = w * h;
    // 1) Couleur de fond (moyenne des 4 coins)
    const corners = [[2,2],[2,w-3],[h-3,2],[h-3,w-3]];
    let bgR = 0, bgG = 0, bgB = 0;
    for (const [cy, cx] of corners) {
      const o = (cy * w + cx) * 4;
      bgR += rgba[o]; bgG += rgba[o+1]; bgB += rgba[o+2];
    }
    bgR /= 4; bgG /= 4; bgB /= 4;

    // 2) Histogramme de teintes (pixels saturés S>50 uniquement)
    const hBins = new Uint32Array(36); // bins de 5°, 0-180
    for (let i = 0; i < n; i++) {
      const o = i * 4;
      const r = rgba[o], g = rgba[o+1], b = rgba[o+2];
      const mx = Math.max(r, g, b), mn = Math.min(r, g, b), d = mx - mn;
      if (mx === 0 || d / mx * 255 < 50) continue;
      let hh;
      if (d === 0) hh = 0;
      else if (mx === r) hh = (g - b) / d + (g < b ? 6 : 0);
      else if (mx === g) hh = (b - r) / d + 2;
      else hh = (r - g) / d + 4;
      hh = Math.round(hh * 30);
      if (hh < 0) hh += 180;
      hBins[Math.min(Math.floor(hh / 5), 35)]++;
    }

    // Teinte dominante
    let topBin = 0, topCount = 0;
    for (let i = 0; i < 36; i++) {
      if (hBins[i] > topCount) { topBin = i; topCount = hBins[i]; }
    }
    const topHue = topBin * 5 + 2.5;

    // Scratch : fond blanc ET teinte orange dominante ET PAS de teal significatif
    // MakeCode a toujours des blocs teal (hue 80-100), Scratch jamais
    const isWhiteBg = bgR > 240 && bgG > 240 && bgB > 240;
    const isOrangeDominant = topHue >= 10 && topHue <= 30;
    const tealPixels = hBins[Math.floor(87.5/5)] + hBins[Math.floor(92.5/5)] + hBins[Math.floor(97.5/5)];
    const totalSat = hBins.reduce((s, v) => s + v, 0);
    const hasTeal = tealPixels > totalSat * 0.05; // >5% de teal = MakeCode

    return {
      source: (isWhiteBg && isOrangeDominant && !hasTeal) ? 'scratch' : 'makecode',
      bgRGB: [Math.round(bgR), Math.round(bgG), Math.round(bgB)],
      topHue: topHue
    };
  }

  // ═══════════════════════════════════════════════════════════════════
  // PRIMITIVES — remplacent OpenCV / NumPy
  // ═══════════════════════════════════════════════════════════════════

  /** RGBA ImageData → niveaux de gris (formule OpenCV BGR2GRAY). */
  function toGray(rgba, w, h) {
    const gray = new Uint8Array(w * h);
    for (let i = 0; i < w * h; i++) {
      const o = i * 4;
      gray[i] = Math.round(0.299 * rgba[o] + 0.587 * rgba[o + 1] + 0.114 * rgba[o + 2]);
    }
    return gray;
  }

  /** RGBA ImageData → canaux HSV séparés (H 0‑180, S 0‑255, V 0‑255). */
  function toHSV(rgba, w, h) {
    const n = w * h;
    const H = new Uint8Array(n), S = new Uint8Array(n), V = new Uint8Array(n);
    for (let i = 0; i < n; i++) {
      const o = i * 4;
      const r = rgba[o], g = rgba[o + 1], b = rgba[o + 2];
      const mx = Math.max(r, g, b), mn = Math.min(r, g, b), d = mx - mn;
      V[i] = mx;
      S[i] = mx === 0 ? 0 : Math.round(d / mx * 255);
      if (d === 0) { H[i] = 0; continue; }
      let hh;
      if (mx === r) hh = (g - b) / d + (g < b ? 6 : 0);
      else if (mx === g) hh = (b - r) / d + 2;
      else hh = (r - g) / d + 4;
      H[i] = Math.round(hh * 30); // *60/2 → [0,180]
    }
    return { H, S, V };
  }

  /** Convertit une couleur RGB en teinte HSV (0-180). */
  function rgbToHue(r, g, b) {
    const mx = Math.max(r, g, b), mn = Math.min(r, g, b), d = mx - mn;
    if (d === 0) return 0;
    let hh;
    if (mx === r) hh = (g - b) / d + (g < b ? 6 : 0);
    else if (mx === g) hh = (b - r) / d + 2;
    else hh = (r - g) / d + 4;
    hh = Math.round(hh * 30);
    if (hh < 0) hh += 180;
    return hh;
  }

  /** Dilatation morphologique avec noyau rectangulaire kw×kh (compatible OpenCV). */
  function dilate(src, w, h, kw, kh) {
    const dst = new Uint8Array(w * h);
    const ax = kw >> 1, ay = kh >> 1;
    const dx0 = -ax, dx1 = kw - ax - 1;
    const dy0 = -ay, dy1 = kh - ay - 1;
    for (let y = 0; y < h; y++) {
      for (let x = 0; x < w; x++) {
        let mx = 0;
        for (let dy = dy0; dy <= dy1; dy++) {
          const yy = y + dy;
          if (yy < 0 || yy >= h) continue;
          for (let dx = dx0; dx <= dx1; dx++) {
            const xx = x + dx;
            if (xx < 0 || xx >= w) continue;
            const v = src[yy * w + xx];
            if (v > mx) mx = v;
          }
        }
        dst[y * w + x] = mx;
      }
    }
    return dst;
  }

  /** Érosion morphologique avec noyau rectangulaire kw×kh (compatible OpenCV). */
  function erode(src, w, h, kw, kh) {
    const dst = new Uint8Array(w * h);
    const ax = kw >> 1, ay = kh >> 1;
    const dx0 = -ax, dx1 = kw - ax - 1;
    const dy0 = -ay, dy1 = kh - ay - 1;
    for (let y = 0; y < h; y++) {
      for (let x = 0; x < w; x++) {
        let mn = 255;
        for (let dy = dy0; dy <= dy1; dy++) {
          const yy = y + dy;
          if (yy < 0 || yy >= h) continue;
          for (let dx = dx0; dx <= dx1; dx++) {
            const xx = x + dx;
            if (xx < 0 || xx >= w) continue;
            const v = src[yy * w + xx];
            if (v < mn) mn = v;
          }
        }
        dst[y * w + x] = mn;
      }
    }
    return dst;
  }

  /** Gradient morphologique = dilate − erode. */
  function morphGradient(src, w, h, kw, kh) {
    const d = dilate(src, w, h, kw, kh);
    const e = erode(src, w, h, kw, kh);
    const out = new Uint8Array(w * h);
    for (let i = 0; i < w * h; i++) out[i] = d[i] - e[i];
    return out;
  }

  /** Fermeture morphologique = dilate puis erode. */
  function morphClose(src, w, h, kw, kh) {
    return erode(dilate(src, w, h, kw, kh), w, h, kw, kh);
  }

  /** Seuillage binaire : > thresh → 255, sinon 0. */
  function threshold(src, w, h, thresh) {
    const out = new Uint8Array(w * h);
    for (let i = 0; i < w * h; i++) out[i] = src[i] > thresh ? 255 : 0;
    return out;
  }

  /** Inversion bit à bit. */
  function bitwiseNot(src, w, h) {
    const out = new Uint8Array(w * h);
    for (let i = 0; i < w * h; i++) out[i] = src[i] ? 0 : 255;
    return out;
  }

  /** OR bit à bit. */
  function bitwiseOr(a, b) {
    const out = new Uint8Array(a.length);
    for (let i = 0; i < a.length; i++) out[i] = a[i] | b[i];
    return out;
  }

  /** AND bit à bit. */
  function bitwiseAnd(a, b) {
    const out = new Uint8Array(a.length);
    for (let i = 0; i < a.length; i++) out[i] = a[i] & b[i];
    return out;
  }

  /**
   * Composantes connexes avec statistiques (connectivity=4).
   * Retourne { numLabels, labels: Int32Array, stats, centroids }.
   */
  function connectedComponents(binary, w, h) {
    const labels = new Int32Array(w * h);
    let nextLabel = 1;
    const parent = [0]; // union‑find, index 0 = fond

    function find(x) {
      while (parent[x] !== x) { parent[x] = parent[parent[x]]; x = parent[x]; }
      return x;
    }
    function union(a, b) {
      a = find(a); b = find(b);
      if (a !== b) parent[Math.max(a, b)] = Math.min(a, b);
    }

    // Passe 1
    for (let y = 0; y < h; y++) {
      for (let x = 0; x < w; x++) {
        const idx = y * w + x;
        if (!binary[idx]) continue;
        const up = y > 0 ? labels[(y - 1) * w + x] : 0;
        const left = x > 0 ? labels[y * w + x - 1] : 0;
        if (!up && !left) { labels[idx] = nextLabel; parent.push(nextLabel); nextLabel++; }
        else if (up && !left) labels[idx] = up;
        else if (!up && left) labels[idx] = left;
        else { labels[idx] = Math.min(up, left); union(up, left); }
      }
    }

    // Résoudre les labels
    const labelMap = new Int32Array(nextLabel);
    let finalLabel = 0;
    for (let i = 1; i < nextLabel; i++) {
      if (find(i) === i) { finalLabel++; labelMap[i] = finalLabel; }
    }
    for (let i = 1; i < nextLabel; i++) labelMap[i] = labelMap[find(i)];
    for (let i = 0; i < w * h; i++) if (labels[i]) labels[i] = labelMap[labels[i]];

    const numLabels = finalLabel + 1;

    // Statistiques
    const area = new Int32Array(numLabels);
    const sumX = new Float64Array(numLabels), sumY = new Float64Array(numLabels);
    const minX = new Int32Array(numLabels).fill(w), minY = new Int32Array(numLabels).fill(h);
    const maxX = new Int32Array(numLabels).fill(-1), maxY = new Int32Array(numLabels).fill(-1);

    for (let y = 0; y < h; y++) {
      for (let x = 0; x < w; x++) {
        const lbl = labels[y * w + x]; if (!lbl) continue;
        area[lbl]++;
        sumX[lbl] += x; sumY[lbl] += y;
        if (x < minX[lbl]) minX[lbl] = x; if (y < minY[lbl]) minY[lbl] = y;
        if (x > maxX[lbl]) maxX[lbl] = x; if (y > maxY[lbl]) maxY[lbl] = y;
      }
    }

    const stats = [], centroids = [];
    for (let i = 0; i < numLabels; i++) {
      stats.push({
        left: minX[i], top: minY[i],
        width: maxX[i] >= 0 ? maxX[i] - minX[i] + 1 : 0,
        height: maxY[i] >= 0 ? maxY[i] - minY[i] + 1 : 0,
        area: area[i]
      });
      centroids.push({
        x: area[i] > 0 ? sumX[i] / area[i] : 0,
        y: area[i] > 0 ? sumY[i] / area[i] : 0
      });
    }
    return { numLabels, labels, stats, centroids };
  }

  /**
   * Distance transform L2 — chamfer 2 passes (3×3, poids 1.0 / 1.414).
   * src : Uint8Array, 0 = obstacle, >0 = espace libre.
   */
  function distanceTransform(src, w, h) {
    const INF = 1e9;
    const dist = new Float32Array(w * h);
    for (let i = 0; i < w * h; i++) dist[i] = src[i] ? INF : 0;
    const a = 1.0, b = 1.4142;
    // Passe avant
    for (let y = 0; y < h; y++) {
      for (let x = 0; x < w; x++) {
        const idx = y * w + x;
        if (dist[idx] === 0) continue;
        let d = dist[idx];
        if (y > 0) { d = Math.min(d, dist[(y - 1) * w + x] + a); }
        if (x > 0) { d = Math.min(d, dist[y * w + x - 1] + a); }
        if (y > 0 && x > 0) { d = Math.min(d, dist[(y - 1) * w + x - 1] + b); }
        if (y > 0 && x < w - 1) { d = Math.min(d, dist[(y - 1) * w + x + 1] + b); }
        // Knight moves (5×5)
        if (y > 1 && x > 0) d = Math.min(d, dist[(y - 2) * w + x - 1] + 2.2);
        if (y > 1 && x < w - 1) d = Math.min(d, dist[(y - 2) * w + x + 1] + 2.2);
        if (y > 0 && x > 1) d = Math.min(d, dist[(y - 1) * w + x - 2] + 2.2);
        if (y > 0 && x < w - 2) d = Math.min(d, dist[(y - 1) * w + x + 2] + 2.2);
        dist[idx] = d;
      }
    }
    // Passe arrière
    for (let y = h - 1; y >= 0; y--) {
      for (let x = w - 1; x >= 0; x--) {
        const idx = y * w + x;
        if (dist[idx] === 0) continue;
        let d = dist[idx];
        if (y < h - 1) d = Math.min(d, dist[(y + 1) * w + x] + a);
        if (x < w - 1) d = Math.min(d, dist[y * w + x + 1] + a);
        if (y < h - 1 && x < w - 1) d = Math.min(d, dist[(y + 1) * w + x + 1] + b);
        if (y < h - 1 && x > 0) d = Math.min(d, dist[(y + 1) * w + x - 1] + b);
        if (y < h - 2 && x > 0) d = Math.min(d, dist[(y + 2) * w + x - 1] + 2.2);
        if (y < h - 2 && x < w - 1) d = Math.min(d, dist[(y + 2) * w + x + 1] + 2.2);
        if (y < h - 1 && x > 1) d = Math.min(d, dist[(y + 1) * w + x - 2] + 2.2);
        if (y < h - 1 && x < w - 2) d = Math.min(d, dist[(y + 1) * w + x + 2] + 2.2);
        dist[idx] = d;
      }
    }
    return dist;
  }

  /**
   * Remplit l'intérieur des contours externes d'un masque (= findContours RETR_EXTERNAL + FILLED).
   * Méthode : flood‑fill depuis l'extérieur ; tout ce qui n'est pas atteint est intérieur.
   */
  function fillExternal(mask, w, h) {
    const pw = w + 2, ph = h + 2;
    const padded = new Uint8Array(pw * ph);
    for (let y = 0; y < h; y++)
      for (let x = 0; x < w; x++)
        padded[(y + 1) * pw + x + 1] = mask[y * w + x] ? 1 : 0;

    const visited = new Uint8Array(pw * ph);
    const stack = [0];
    visited[0] = 1;
    while (stack.length > 0) {
      const idx = stack.pop();
      const py = (idx / pw) | 0, px = idx % pw;
      const nb = [[-1, 0], [1, 0], [0, -1], [0, 1]];
      for (const [dy, dx] of nb) {
        const ny = py + dy, nx = px + dx;
        if (ny < 0 || ny >= ph || nx < 0 || nx >= pw) continue;
        const ni = ny * pw + nx;
        if (visited[ni] || padded[ni]) continue;
        visited[ni] = 1;
        stack.push(ni);
      }
    }
    const result = new Uint8Array(w * h);
    for (let y = 0; y < h; y++)
      for (let x = 0; x < w; x++)
        if (!visited[(y + 1) * pw + x + 1]) result[y * w + x] = 255;
    return result;
  }

  /** Crée un masque à partir de labels === lbl. */
  function maskFromLabel(labels, lbl, n) {
    const m = new Uint8Array(n);
    for (let i = 0; i < n; i++) if (labels[i] === lbl) m[i] = 255;
    return m;
  }

  /** Nombre de pixels non‑nuls. */
  function countNonZero(arr) {
    let c = 0; for (let i = 0; i < arr.length; i++) if (arr[i]) c++;
    return c;
  }

  /** Moyenne d'un canal (Uint8Array) sur un masque. */
  function meanMasked(chan, mask, n) {
    let sum = 0, cnt = 0;
    for (let i = 0; i < n; i++) if (mask[i]) { sum += chan[i]; cnt++; }
    return cnt > 0 ? sum / cnt : 0;
  }

  /** Moyenne RGB sur un masque → [R,G,B]. */
  function meanRGBMasked(rgba, mask, n) {
    let sr = 0, sg = 0, sb = 0, cnt = 0;
    for (let i = 0; i < n; i++) {
      if (!mask[i]) continue;
      const o = i * 4;
      sr += rgba[o]; sg += rgba[o + 1]; sb += rgba[o + 2]; cnt++;
    }
    return cnt > 0 ? [sr / cnt, sg / cnt, sb / cnt] : [128, 128, 128];
  }

  /** Copie d'un Uint8Array. */
  function copyMask(m) { return new Uint8Array(m); }

  /** Deux régions ont-elles la même couleur de remplissage (= morceaux d'un même bloc) ? */
  function sameFill(r1, r2) {
    const dr = r1.meanColor[0] - r2.meanColor[0];
    const dg = r1.meanColor[1] - r2.meanColor[1];
    const db = r1.meanColor[2] - r2.meanColor[2];
    return Math.sqrt(dr * dr + dg * dg + db * db) <= SAME_FILL_DIST;
  }

  /**
   * `r` est-il un bloc IMBRIQUÉ dans le conteneur `c` (et non un morceau de `c`) ?
   * Vrai quand r tient entièrement dans c, est nettement plus court que lui et
   * n'a pas la même couleur de remplissage → un bloc posé dans l'encoche du « si ».
   */
  function nestedInContainer(r, c) {
    if (sameFill(r, c)) return false;
    const [rx, ry, rw, rh] = r.bbox, [cx, cy, cw, ch] = c.bbox;
    if (ch <= CONTAINER_HEIGHT_THRESHOLD || rh > ch * 0.6) return false;
    const ovX = Math.max(0, Math.min(cx + cw, rx + rw) - Math.max(cx, rx));
    const ovY = Math.max(0, Math.min(cy + ch, ry + rh) - Math.max(cy, ry));
    const a = rw * rh;
    return a > 0 && (ovX * ovY) / a >= 0.9;
  }

  /** OR en place : dst |= src. */
  function orInPlace(dst, src) { for (let i = 0; i < dst.length; i++) dst[i] = dst[i] | src[i]; }

  // ═══════════════════════════════════════════════════════════════════
  // PHASE 1 : Détection des bordures
  // ═══════════════════════════════════════════════════════════════════
  function detectBorders(gray, w, h) {
    const grad = morphGradient(gray, w, h, 3, 3);
    const th = threshold(grad, w, h, 5);
    return morphClose(th, w, h, 2, 2);
  }

  // ═══════════════════════════════════════════════════════════════════
  // PHASE 2 : Composantes connexes + identification du fond
  // ═══════════════════════════════════════════════════════════════════
  function findRegions(rgba, hsvH, hsvS, hsvV, borderMask, w, h) {
    const fillable = bitwiseNot(borderMask, w, h);
    const cc = connectedComponents(fillable, w, h);
    const n = w * h;

    // Labels du fond (coins)
    const bgLabels = new Set();
    const corners = [[2, 2], [2, w - 3], [h - 3, 2], [h - 3, w - 3]];
    for (const [cy, cx] of corners) {
      if (cy >= 0 && cy < h && cx >= 0 && cx < w) {
        const lbl = cc.labels[cy * w + cx];
        if (lbl > 0) bgLabels.add(lbl);
      }
    }

    // Couleur moyenne du fond
    const bgMask = new Uint8Array(n);
    for (let i = 0; i < n; i++) if (bgLabels.has(cc.labels[i])) bgMask[i] = 255;
    const bgColor = countNonZero(bgMask) > 0 ? meanRGBMasked(rgba, bgMask, n) : [229, 231, 235];

    const regions = [];
    for (let lbl = 1; lbl < cc.numLabels; lbl++) {
      const st = cc.stats[lbl];
      if (st.area < MIN_REGION_AREA) continue;
      const mask = maskFromLabel(cc.labels, lbl, n);
      const meanRGB = meanRGBMasked(rgba, mask, n);
      const mH = meanMasked(hsvH, mask, n);
      const mS = meanMasked(hsvS, mask, n);
      const mV = meanMasked(hsvV, mask, n);
      const colorDist = Math.sqrt(
        (meanRGB[0] - bgColor[0]) ** 2 + (meanRGB[1] - bgColor[1]) ** 2 + (meanRGB[2] - bgColor[2]) ** 2);
      const isBg = bgLabels.has(lbl) || (colorDist < 25 && mS < 25);
      regions.push({
        label: lbl, area: st.area,
        bbox: [st.left, st.top, st.width, st.height],
        centroid: [cc.centroids[lbl].x, cc.centroids[lbl].y],
        meanHue: mH, meanSat: mS, meanVal: mV,
        meanColor: meanRGB, // RGB
        isBackground: isBg, mask
      });
    }
    return { regions, bgColor };
  }

  // ═══════════════════════════════════════════════════════════════════
  // PHASE 3 : Fusion des régions en blocs
  // ═══════════════════════════════════════════════════════════════════
  function mergeRegionsToBlocks(regions, w, h) {
    const blockRegions = regions.filter(r => !r.isBackground);
    const n = blockRegions.length;
    const parent = Array.from({ length: n }, (_, i) => i);

    function find(x) { while (parent[x] !== x) { parent[x] = parent[parent[x]]; x = parent[x]; } return x; }
    function union(a, b) { const ra = find(a), rb = find(b); if (ra !== rb) parent[ra] = rb; }

    // 3a. Proximité horizontale + couleur
    for (let i = 0; i < n; i++) {
      const r1 = blockRegions[i];
      const [x1, y1, w1, h1] = r1.bbox;
      for (let j = i + 1; j < n; j++) {
        const r2 = blockRegions[j];
        const [x2, y2, w2, h2] = r2.bbox;
        let hueDiff = Math.abs(r1.meanHue - r2.meanHue);
        hueDiff = Math.min(hueDiff, 180 - hueDiff);
        const valDiff = Math.abs(r1.meanVal - r2.meanVal);
        if (hueDiff > HUE_MERGE_THRESHOLD || valDiff > VAL_MERGE_THRESHOLD) continue;
        // Un bloc posé DANS un conteneur n'est jamais un morceau de ce conteneur :
        // teintes voisines (deux verts/bleus proches) + adjacence ne suffisent pas.
        if (nestedInContainer(r1, r2) || nestedInContainer(r2, r1)) continue;
        const yOv = Math.max(0, Math.min(y1 + h1, y2 + h2) - Math.max(y1, y2));
        const mh = Math.min(h1, h2);
        if (mh === 0 || yOv / mh < 0.4) continue;
        if (Math.max(0, Math.max(x1, x2) - Math.min(x1 + w1, x2 + w2)) > 30) continue;
        const dilated = dilate(r1.mask, w, h, 5, 5);
        if (countNonZero(bitwiseAnd(dilated, r2.mask)) > 0) union(i, j);
      }
    }

    // Absorption d'un fragment : il rejoint son parent le plus SERRÉ.
    // Un fragment posé dans un bloc enfant (ex. le menu « P2 » d'un « mettre servo »)
    // est aussi géométriquement contenu dans le conteneur « si » qui entoure ce bloc :
    // s'il fusionnait avec les deux, il servirait de pont et collerait définitivement
    // l'enfant au conteneur — c'est ce qui empêchait d'extraire les blocs posés
    // dans un « si » de teinte voisine.
    // Les bandes de conteneur ainsi laissées de côté (morceau de la barre de
    // condition coupé par un champ posé dessus) sont récupérées plus loin, en
    // phase 4j, qui absorbe dans le conteneur tout enfant bas (≤ FIELD_HEIGHT)
    // de même couleur.
    function absorbFragment(i, cands) {
      if (!cands.length) return;
      let tight = cands[0];
      for (const c of cands) if (blockRegions[c].area < blockRegions[tight].area) tight = c;
      union(i, tight);
    }

    // 3b. Absorption des petites régions
    for (let i = 0; i < n; i++) {
      const r = blockRegions[i];
      if (r.area >= 200) continue;
      const [x, y, rw, rh] = r.bbox;
      const cands = [];
      for (let j = 0; j < n; j++) {
        if (i === j) continue;
        const rj = blockRegions[j];
        if (rj.area <= r.area) continue;
        const [xj, yj, wj, hj] = rj.bbox;
        if (xj > x || yj > y || xj + wj < x + rw || yj + hj < y + rh) continue;
        let hd = Math.abs(r.meanHue - rj.meanHue);
        hd = Math.min(hd, 180 - hd);
        if (hd < 20) cands.push(j);
      }
      absorbFragment(i, cands);
    }

    // 3c. Containment des fragments
    for (let i = 0; i < n; i++) {
      const ri = blockRegions[i];
      if (ri.area > SMALL_FRAGMENT_AREA) continue;
      const [xi, yi, wi, hi] = ri.bbox;
      const smBboxArea = wi * hi;
      if (smBboxArea <= 0) continue;
      const cands = [];
      for (let j = 0; j < n; j++) {
        if (i === j) continue;
        const rj = blockRegions[j];
        if (rj.area <= ri.area) continue;
        let hd = Math.abs(ri.meanHue - rj.meanHue);
        hd = Math.min(hd, 180 - hd);
        const vd = Math.abs(ri.meanVal - rj.meanVal);
        if (hd > HUE_MERGE_THRESHOLD || vd > VAL_MERGE_THRESHOLD) continue;
        const [xj, yj, wj, hj] = rj.bbox;
        const ovX = Math.max(0, Math.min(xj + wj, xi + wi) - Math.max(xj, xi));
        const ovY = Math.max(0, Math.min(yj + hj, yi + hi) - Math.max(yj, yi));
        if ((ovX * ovY) / smBboxArea <= 0.6) continue;
        cands.push(j);
      }
      absorbFragment(i, cands);
    }

    // Construire les blocs fusionnés
    const groups = {};
    for (let i = 0; i < n; i++) {
      const root = find(i);
      if (!groups[root]) groups[root] = [];
      groups[root].push(i);
    }

    const blocks = [];
    for (const indices of Object.values(groups)) {
      const merged = new Uint8Array(w * h);
      let totalArea = 0;
      const colors = [], hues = [];
      for (const idx of indices) {
        const r = blockRegions[idx];
        orInPlace(merged, r.mask);
        totalArea += r.area;
        colors.push(r.meanColor);
        hues.push(r.meanHue);
      }
      // BBox
      let mnx = w, mny = h, mxx = -1, mxy = -1;
      for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
        if (!merged[y * w + x]) continue;
        if (x < mnx) mnx = x; if (y < mny) mny = y;
        if (x > mxx) mxx = x; if (y > mxy) mxy = y;
      }
      if (mxx < 0) continue;
      const meanCol = colors.reduce((a, c) => [a[0] + c[0], a[1] + c[1], a[2] + c[2]], [0, 0, 0]).map(v => v / colors.length);
      const meanH = hues.reduce((a, v) => a + v, 0) / hues.length;
      blocks.push({
        rawMask: merged,
        bbox: [mnx, mny, mxx - mnx + 1, mxy - mny + 1],
        area: totalArea,
        meanColor: meanCol,
        meanHue: meanH,
        regionCount: indices.length
      });
    }
    blocks.sort((a, b) => a.bbox[1] - b.bbox[1] || a.bbox[0] - b.bbox[0]);
    return blocks;
  }

  // ═══════════════════════════════════════════════════════════════════
  // PHASE 3b : Span fill sur les conteneurs
  // ═══════════════════════════════════════════════════════════════════
  function spanFillContainers(blocks, w) {
    let count = 0;
    for (const block of blocks) {
      if (block.bbox[3] <= CONTAINER_HEIGHT_THRESHOLD) continue;
      block.preSpanMask = copyMask(block.rawMask);
      const raw = block.rawMask;
      for (let y = 0; y < raw.length / w; y++) {
        let first = -1, last = -1;
        for (let x = 0; x < w; x++) {
          if (raw[y * w + x]) { if (first < 0) first = x; last = x; }
        }
        if (first >= 0 && last > first) {
          for (let x = first; x <= last; x++) raw[y * w + x] = 255;
        }
      }
      count++;
    }
    return count;
  }

  // ═══════════════════════════════════════════════════════════════════
  // PHASE 4 : Masques finaux
  // ═══════════════════════════════════════════════════════════════════
  function buildFinalMasks(blocks, rgba, hsvH, hsvS, hsvV, borderMask, bgColor, w, h) {
    const n = w * h;

    // 4a. Fill contour externe
    const filledMasks = blocks.map(b => fillExternal(b.rawMask, w, h));

    // 4b. Distance transform pour assignation des bordures
    const blockNearest = new Int32Array(n);
    const blockMinDist = new Float32Array(n).fill(1e9);
    for (let i = 0; i < filledMasks.length; i++) {
      const inv = new Uint8Array(n);
      for (let j = 0; j < n; j++) inv[j] = filledMasks[i][j] ? 0 : 255;
      const dist = distanceTransform(inv, w, h);
      for (let j = 0; j < n; j++) {
        if (dist[j] < blockMinDist[j]) { blockMinDist[j] = dist[j]; blockNearest[j] = i + 1; }
      }
    }

    // 4c. Masques finaux = filled + bordures assignées
    const finalMasks = [];
    for (let i = 0; i < filledMasks.length; i++) {
      const fm = copyMask(filledMasks[i]);
      const inv = new Uint8Array(n);
      for (let j = 0; j < n; j++) inv[j] = fm[j] ? 0 : 255;
      const distFromThis = distanceTransform(inv, w, h);
      for (let j = 0; j < n; j++) {
        if (borderMask[j] && distFromThis[j] <= BORDER_ASSIGN_DIST && blockNearest[j] === i + 1) {
          fm[j] = 255;
        }
      }
      finalMasks.push(fm);
    }

    // 4d. Clipping intelligent
    for (let i = 0; i < blocks.length; i++) {
      const fm = finalMasks[i];
      const raw = blocks[i].rawMask;
      const invRaw = new Uint8Array(n);
      for (let j = 0; j < n; j++) invRaw[j] = raw[j] ? 0 : 255;
      const distFromRaw = distanceTransform(invRaw, w, h);
      const nearRaw = new Uint8Array(n);
      for (let j = 0; j < n; j++) if (distFromRaw[j] <= BORDER_ASSIGN_DIST) nearRaw[j] = 1;

      // Composantes connexes de l'inverse du raw → détecter zones enclosues
      const ccInv = connectedComponents(invRaw, w, h);
      const enclosed = new Uint8Array(n);
      for (let lbl = 1; lbl < ccInv.numLabels; lbl++) {
        const st = ccInv.stats[lbl];
        if (st.left === 0 || st.top === 0 || st.left + st.width >= w || st.top + st.height >= h) continue;
        for (let j = 0; j < n; j++) if (ccInv.labels[j] === lbl) enclosed[j] = 1;
      }

      for (let j = 0; j < n; j++) {
        if (!(raw[j] || nearRaw[j] || enclosed[j])) fm[j] = 0;
      }
    }

    // 4e. Nettoyage couleur itératif (3 passes)
    // Rangées protégées pour conteneurs
    const containerProtectedRows = [];
    for (let i = 0; i < blocks.length; i++) {
      const [xB, yB, bw, bh] = blocks[i].bbox;
      if (bh <= CONTAINER_HEIGHT_THRESHOLD || !blocks[i].preSpanMask) {
        containerProtectedRows.push(null); continue;
      }
      const pre = blocks[i].preSpanMask;
      const rightHalfX = xB + (bw >> 1);
      const prot = new Set();
      for (let absY = yB; absY < yB + bh; absY++) {
        let lastCol = -1;
        for (let x = w - 1; x >= 0; x--) { if (pre[absY * w + x]) { lastCol = x; break; } }
        if (lastCol > rightHalfX) prot.add(absY);
      }
      containerProtectedRows.push(prot);
    }

    // Couleur moyenne par bloc (sur le rawMask)
    const blockMeanRGBs = blocks.map(b => meanRGBMasked(rgba, b.rawMask, n));

    const bgR = bgColor[0], bgG = bgColor[1], bgB = bgColor[2];

    for (let pass = 0; pass < 3; pass++) {
      let removedTotal = 0;
      for (let i = 0; i < blocks.length; i++) {
        const fm = finalMasks[i], raw = blocks[i].rawMask;
        const meanBGR = blockMeanRGBs[i];
        const eroded = erode(fm, w, h, 3, 3);
        const prot = containerProtectedRows[i];

        for (let idx = 0; idx < n; idx++) {
          if (!(fm[idx] && !eroded[idx] && !raw[idx])) continue;
          const ey = (idx / w) | 0, ex = idx % w;
          if (prot && prot.has(ey)) continue;

          const o = idx * 4;
          const pR = rgba[o], pG = rgba[o + 1], pB = rgba[o + 2];
          const pSat = hsvS[idx], pHue = hsvH[idx];

          if (pSat < 40) {
            const bgDist = Math.sqrt((pR - bgR) ** 2 + (pG - bgG) ** 2 + (pB - bgB) ** 2);
            if (bgDist < 40) { fm[idx] = 0; removedTotal++; }
            continue;
          }

          // Référence couleur locale
          let refR = -1, refG = -1, refB = -1;
          const offsets = [[3, 0], [4, 0], [5, 0], [6, 0], [7, 0], [8, 0],
            [-3, 0], [-4, 0], [-5, 0], [-6, 0],
            [0, -3], [0, -4], [0, -5], [0, 3], [0, 4], [0, 5]];
          for (const [dy, dx] of offsets) {
            const ry = ey + dy, rx = ex + dx;
            if (ry < 0 || ry >= h || rx < 0 || rx >= w) continue;
            const ri = ry * w + rx;
            if (raw[ri] && hsvS[ri] > 40) {
              const ro = ri * 4;
              refR = rgba[ro]; refG = rgba[ro + 1]; refB = rgba[ro + 2]; break;
            }
          }
          if (refR < 0) { refR = meanBGR[0]; refG = meanBGR[1]; refB = meanBGR[2]; }
          if (Math.sqrt((pR - refR) ** 2 + (pG - refG) ** 2 + (pB - refB) ** 2) > 40) {
            fm[idx] = 0; removedTotal++; continue;
          }

          // Référence teinte locale
          let refHue = -1;
          const hOffsets = [[3, 0], [4, 0], [5, 0], [-3, 0], [-4, 0], [0, -3], [0, 3]];
          for (const [dy, dx] of hOffsets) {
            const ry = ey + dy, rx = ex + dx;
            if (ry < 0 || ry >= h || rx < 0 || rx >= w) continue;
            const ri = ry * w + rx;
            if (raw[ri] && hsvS[ri] > 40) { refHue = hsvH[ri]; break; }
          }
          if (refHue < 0) refHue = blocks[i].meanHue;
          let hueDiff = Math.abs(pHue - refHue);
          hueDiff = Math.min(hueDiff, 180 - hueDiff);
          if (hueDiff > 8) { fm[idx] = 0; removedTotal++; }
        }
      }
      if (removedTotal === 0) break;
    }

    // 4g. Nettoyage géométrique des encoches de conteneurs
    cleanContainerNotches(blocks, finalMasks, hsvH, hsvS, w, h);

    // 4h. Restauration des barres de condition
    restoreConditionBars(blocks, finalMasks, w, h);

    // 4i. Nettoyage final par teinte serrée
    tightHueCleanup(blocks, finalMasks, hsvH, hsvS, w, h);

    return finalMasks;
  }

  // ─── Phase 4g ───
  function cleanContainerNotches(blocks, finalMasks, hsvH, hsvS, w, h) {
    for (let i = 0; i < blocks.length; i++) {
      const [xB, yB, bw, bh] = blocks[i].bbox;
      if (bh <= CONTAINER_HEIGHT_THRESHOLD) continue;
      const fm = finalMasks[i], raw = blocks[i].rawMask;
      const pre = blocks[i].preSpanMask;
      if (!pre) continue;

      // Teinte dominante
      let hist = new Int32Array(180);
      for (let j = 0; j < w * h; j++) {
        if (pre[j] && hsvS[j] > 80) hist[hsvH[j]]++;
      }
      let domHue = 0, domMax = 0;
      for (let k = 0; k < 180; k++) if (hist[k] > domMax) { domMax = hist[k]; domHue = k; }
      const dominantHue = domHue + 0.5;

      // Zone condition
      const rightHalfX = xB + (bw >> 1);
      let condZoneEnd = yB;
      for (let absY = yB; absY < yB + bh; absY++) {
        let lastCol = -1;
        for (let x = w - 1; x >= 0; x--) { if (pre[absY * w + x]) { lastCol = x; break; } }
        if (lastCol > rightHalfX) condZoneEnd = absY + 1; else break;
      }

      // Distance au pre_span_mask
      const invPre = new Uint8Array(w * h);
      for (let j = 0; j < w * h; j++) invPre[j] = pre[j] ? 0 : 255;
      const distFromPre = distanceTransform(invPre, w, h);

      for (let y = yB; y < yB + bh; y++) {
        if (y < condZoneEnd) continue;
        for (let x = xB; x < xB + bw; x++) {
          const idx = y * w + x;
          if (!fm[idx]) continue;
          const dist = distFromPre[idx];
          const pSat = hsvS[idx], pHue = hsvH[idx];
          let hd = Math.abs(pHue - dominantHue);
          hd = Math.min(hd, 180 - hd);
          if ((dist > NOTCH_DIST_THRESHOLD && pSat > 40) || (hd > NOTCH_HUE_THRESHOLD && pSat > 60)) {
            fm[idx] = 0;
          }
        }
      }
    }
  }

  // ─── Phase 4h ───
  function restoreConditionBars(blocks, finalMasks, w, h) {
    for (let i = 0; i < blocks.length; i++) {
      const [xB, yB, bw, bh] = blocks[i].bbox;
      if (bh <= CONTAINER_HEIGHT_THRESHOLD) continue;
      const pre = blocks[i].preSpanMask;
      if (!pre) continue;
      const fm = finalMasks[i], raw = blocks[i].rawMask;
      const rightHalfX = xB + (bw >> 1);
      for (let absY = yB; absY < yB + bh; absY++) {
        let lastCol = -1;
        for (let x = w - 1; x >= 0; x--) { if (pre[absY * w + x]) { lastCol = x; break; } }
        if (lastCol <= rightHalfX) continue;
        for (let x = 0; x < w; x++) {
          const idx = absY * w + x;
          if (raw[idx] && !fm[idx]) fm[idx] = 255;
        }
      }
    }
  }

  // ─── Phase 4i ───
  function tightHueCleanup(blocks, finalMasks, hsvH, hsvS, w, h) {
    for (let i = 0; i < blocks.length; i++) {
      const [xB, yB, bw, bh] = blocks[i].bbox;
      if (bh <= CONTAINER_HEIGHT_THRESHOLD) continue;
      const pre = blocks[i].preSpanMask;
      if (!pre) continue;
      const fm = finalMasks[i];

      // Teinte dominante
      let hist = new Int32Array(180);
      for (let j = 0; j < w * h; j++) {
        if (pre[j] && hsvS[j] > 80) hist[hsvH[j]]++;
      }
      let domHue = 0, domMax = 0;
      for (let k = 0; k < 180; k++) if (hist[k] > domMax) { domMax = hist[k]; domHue = k; }
      if (domMax === 0) continue;
      const dominantHue = domHue + 0.5;

      // Zone protégée dynamique
      const midY = yB + (bh >> 1);
      let minSubTop = yB + bh, maxSubBot = yB;
      for (let j = 0; j < blocks.length; j++) {
        if (j === i) continue;
        const [ox, oy, ow, oh] = blocks[j].bbox;
        if (oh > CONTAINER_HEIGHT_THRESHOLD) continue;
        if (ox < xB || ox + ow > xB + bw) continue;
        const ocy = oy + (oh >> 1);
        if (ocy < yB || ocy > midY) continue;
        if (oy < minSubTop) minSubTop = oy;
        if (oy + oh > maxSubBot) maxSubBot = oy + oh;
      }

      let protStart, protEnd;
      if (maxSubBot <= yB) {
        let firstOpaque = yB;
        for (let absY = yB; absY < yB + bh; absY++) {
          let found = false;
          for (let x = 0; x < w; x++) if (fm[absY * w + x]) { found = true; break; }
          if (found) { firstOpaque = absY; break; }
        }
        protStart = firstOpaque + 8; protEnd = firstOpaque + 35;
      } else {
        protStart = minSubTop - 1; protEnd = maxSubBot + 1;
      }

      for (let y = yB; y < yB + bh; y++) {
        if (y >= protStart && y < protEnd) continue;
        for (let x = xB; x < xB + bw; x++) {
          const idx = y * w + x;
          if (!fm[idx]) continue;
          const pSat = hsvS[idx];
          if (pSat <= 50) continue;
          let hd = Math.abs(hsvH[idx] - dominantHue);
          hd = Math.min(hd, 180 - hd);
          if (hd > TIGHT_HUE_THRESHOLD) fm[idx] = 0;
        }
      }
    }
  }

  // ═══════════════════════════════════════════════════════════════════
  // PHASE 4j : Fusion des sous-éléments
  // ═══════════════════════════════════════════════════════════════════
  function mergeSubElements(blocks, finalMasks, w, h) {
    const n = blocks.length;
    const absorbed = new Set();

    // BBoxes effectives
    const bboxes = blocks.map((b, i) => {
      const fm = finalMasks[i];
      let mnx = w, mny = h, mxx = -1, mxy = -1;
      for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
        if (!fm[y * w + x]) continue;
        if (x < mnx) mnx = x; if (y < mny) mny = y;
        if (x > mxx) mxx = x; if (y > mxy) mxy = y;
      }
      return mxx >= 0 ? [mnx, mny, mxx + 1, mxy + 1] : [b.bbox[0], b.bbox[1], b.bbox[0] + b.bbox[2], b.bbox[1] + b.bbox[3]];
    });

    // A) Fusion des champs arrondis
    // Petits (< DIAMOND_MIN_WIDTH) : toujours absorber
    // Plus larges : absorber seulement si même couleur que parent (= partie du conteneur)
    for (let pi = 0; pi < n; pi++) {
      if (absorbed.has(pi)) continue;
      const [px1, py1, px2, py2] = bboxes[pi];
      for (let ci = 0; ci < n; ci++) {
        if (ci === pi || absorbed.has(ci)) continue;
        const [cx1, cy1, cx2, cy2] = bboxes[ci];
        const childH = cy2 - cy1, childW = cx2 - cx1;
        if (childH > FIELD_HEIGHT_THRESHOLD) continue;
        if (childW > DIAMOND_MIN_WIDTH || childH >= STATEMENT_MIN_HEIGHT) {
          // Enfant trop grand pour être un champ : ne l'absorber que s'il a la couleur du
          // parent (= morceau du corps du conteneur). Couleur différente → c'est un diamond
          // ou un bloc-instruction posé dans l'encoche : il doit rester une pièce à part.
          const pc = blocks[pi].meanColor, cc = blocks[ci].meanColor;
          const dr = pc[0] - cc[0], dg = pc[1] - cc[1], db = pc[2] - cc[2];
          const colorDist = Math.sqrt(dr * dr + dg * dg + db * db);
          if (colorDist > 60) continue;
        }
        if (cx1 < px1 || cy1 < py1 || cx2 > px2 || cy2 > py2) continue;
        if (blocks[ci].area >= blocks[pi].area) continue;
        orInPlace(finalMasks[pi], finalMasks[ci]);
        absorbed.add(ci);
      }
    }

    // B) Fusion dans les encoches de conteneurs
    const NOTCH_INDENT = 25;
    const containerIndices = [];
    for (let i = 0; i < n; i++) {
      if (!absorbed.has(i) && (bboxes[i][3] - bboxes[i][1]) > CONTAINER_HEIGHT_THRESHOLD)
        containerIndices.push(i);
    }

    const childToParent = {};
    for (let j = 0; j < n; j++) {
      if (absorbed.has(j)) continue;
      const [jx1, jy1, jx2, jy2] = bboxes[j];
      const childH = jy2 - jy1, childW = jx2 - jx1;
      if (childH > FIELD_HEIGHT_THRESHOLD) continue;
      let bestParent = null, bestArea = Infinity;
      for (const ci of containerIndices) {
        if (ci === j) continue;
        const [cx1, cy1, cx2, cy2] = bboxes[ci];
        if (jy1 < cy1 - 4 || jy1 >= cy2) continue;  // tolérance 4px pour les notches
        if (jx1 - cx1 < NOTCH_INDENT) continue;
        // Enfant trop grand pour être un champ et de couleur différente = diamond ou
        // bloc-instruction posé dans l'encoche : ne pas absorber (même règle qu'en A)
        if (childW > DIAMOND_MIN_WIDTH || childH >= STATEMENT_MIN_HEIGHT) {
          const pc = blocks[ci].meanColor, cc = blocks[j].meanColor;
          const dr = pc[0] - cc[0], dg = pc[1] - cc[1], db = pc[2] - cc[2];
          if (Math.sqrt(dr * dr + dg * dg + db * db) > 60) continue;
        }
        const area = (cx2 - cx1) * (cy2 - cy1);
        if (area < bestArea) { bestArea = area; bestParent = ci; }
      }
      if (bestParent !== null) childToParent[j] = bestParent;
    }

    // Fusion intérieur→extérieur
    const parentsBySize = [...new Set(Object.values(childToParent))].sort((a, b) => {
      const aa = (bboxes[a][2] - bboxes[a][0]) * (bboxes[a][3] - bboxes[a][1]);
      const bb = (bboxes[b][2] - bboxes[b][0]) * (bboxes[b][3] - bboxes[b][1]);
      return aa - bb;
    });

    for (const pi of parentsBySize) {
      if (absorbed.has(pi)) continue;
      for (const [cStr, pVal] of Object.entries(childToParent)) {
        const ci = parseInt(cStr);
        if (pVal !== pi || absorbed.has(ci)) continue;
        orInPlace(finalMasks[pi], finalMasks[ci]);
        absorbed.add(ci);
      }
      // Mettre à jour bbox
      let mnx = w, mny = h, mxx = -1, mxy = -1;
      for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
        if (!finalMasks[pi][y * w + x]) continue;
        if (x < mnx) mnx = x; if (y < mny) mny = y;
        if (x > mxx) mxx = x; if (y > mxy) mxy = y;
      }
      if (mxx >= 0) bboxes[pi] = [mnx, mny, mxx + 1, mxy + 1];
    }

    if (absorbed.size === 0) return { blocks, finalMasks };

    // Reconstruire
    const newBlocks = [], newMasks = [];
    for (let i = 0; i < n; i++) {
      if (absorbed.has(i)) continue;
      const fm = finalMasks[i];
      let mnx = w, mny = h, mxx = -1, mxy = -1;
      for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
        if (!fm[y * w + x]) continue;
        if (x < mnx) mnx = x; if (y < mny) mny = y;
        if (x > mxx) mxx = x; if (y > mxy) mxy = y;
      }
      if (mxx >= 0) blocks[i].bbox = [mnx, mny, mxx - mnx + 1, mxy - mny + 1];
      newBlocks.push(blocks[i]);
      newMasks.push(fm);
    }
    return { blocks: newBlocks, finalMasks: newMasks };
  }

  // ═══════════════════════════════════════════════════════════════════
  // PHASE 4j2 : Fusion horizontale des fragments de même teinte
  // Les dropdowns MakeCode coupent les diamonds en morceaux.
  // On fusionne les petits blocs de même teinte sur la même ligne.
  // ═══════════════════════════════════════════════════════════════════
  function mergeHorizontalFragments(blocks, finalMasks, rgba, w, h) {
    const n = blocks.length;
    const absorbed = new Set();

    // Calculer la teinte dominante de chaque bloc
    const hues = blocks.map((b, idx) => {
      const [bx, by, bw, bh] = b.bbox;
      let sumH = 0, cnt = 0;
      for (let y = by; y < by + bh && y < h; y++) {
        for (let x = bx; x < bx + bw && x < w; x++) {
          if (!finalMasks[idx][y * w + x]) continue;
          const o = (y * w + x) * 4;
          const r = rgba[o], g = rgba[o + 1], bl = rgba[o + 2];
          const mx = Math.max(r, g, bl), mn = Math.min(r, g, bl);
          const sat = mx > 0 ? (mx - mn) / mx * 255 : 0;
          if (sat < 50) continue;
          let hh = 0;
          if (mx === mn) hh = 0;
          else if (mx === r) hh = 30 * (g - bl) / (mx - mn);
          else if (mx === g) hh = 60 + 30 * (bl - r) / (mx - mn);
          else hh = 120 + 30 * (r - g) / (mx - mn);
          if (hh < 0) hh += 180;
          sumH += hh; cnt++;
        }
      }
      return cnt > 0 ? sumH / cnt : -1;
    });

    // Passe 1 : fusionner les grands fragments (≥40px) de même teinte
    for (let i = 0; i < n; i++) {
      if (absorbed.has(i)) continue;
      const [bx1, by1, bw1, bh1] = blocks[i].bbox;
      if (bh1 > FIELD_HEIGHT_THRESHOLD) continue;
      if (bw1 < 40) continue;
      if (hues[i] < 0) continue;

      let merged = false;
      for (let j = i + 1; j < n; j++) {
        if (absorbed.has(j)) continue;
        const [bx2, by2, bw2, bh2] = blocks[j].bbox;
        if (bh2 > FIELD_HEIGHT_THRESHOLD) continue;
        if (bw2 < 40) continue;
        if (hues[j] < 0) continue;

        let hueDiff = Math.abs(hues[i] - hues[j]);
        if (hueDiff > 90) hueDiff = 180 - hueDiff;
        if (hueDiff > 15) continue;

        const yTop = Math.max(by1, by2);
        const yBot = Math.min(by1 + bh1, by2 + bh2);
        const yOverlap = yBot - yTop;
        const minH = Math.min(bh1, bh2);
        if (yOverlap < minH * 0.4) continue;

        const left1 = bx1, right1 = bx1 + bw1;
        const left2 = bx2, right2 = bx2 + bw2;
        const gap = Math.max(left1, left2) - Math.min(right1, right2);
        if (gap < 10 || gap > 120) continue;

        orInPlace(finalMasks[i], finalMasks[j]);
        absorbed.add(j);
        merged = true;

        // Absorber les petits blocs ENTRE i et j
        const gapLeft = Math.min(right1, right2);
        const gapRight = Math.max(left1, left2);
        for (let k = 0; k < n; k++) {
          if (k === i || k === j || absorbed.has(k)) continue;
          const [bxk, byk, bwk, bhk] = blocks[k].bbox;
          if (bhk > FIELD_HEIGHT_THRESHOLD) continue;
          if (bxk < gapLeft - 5 || bxk + bwk > gapRight + 5) continue;
          const yOvlpK = Math.min(by1 + bh1, byk + bhk) - Math.max(by1, byk);
          if (yOvlpK < Math.min(bh1, bhk) * 0.3) continue;
          orInPlace(finalMasks[i], finalMasks[k]);
          absorbed.add(k);
        }
      }

      if (merged) {
        let mnx = w, mny = h, mxx = -1, mxy = -1;
        for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
          if (!finalMasks[i][y * w + x]) continue;
          if (x < mnx) mnx = x; if (y < mny) mny = y;
          if (x > mxx) mxx = x; if (y > mxy) mxy = y;
        }
        if (mxx >= 0) blocks[i].bbox = [mnx, mny, mxx - mnx + 1, mxy - mny + 1];
      }
    }

    // Passe 2 : chaque grand fragment (≥40px) absorbe les petits de même teinte
    // sur la même ligne (cas "distance ultrason cm ▼ > ▼ 4" : 1 gros + N petits)
    for (let i = 0; i < n; i++) {
      if (absorbed.has(i)) continue;
      const [bx1, by1, bw1, bh1] = blocks[i].bbox;
      if (bh1 > FIELD_HEIGHT_THRESHOLD) continue;
      if (bw1 < 40) continue;
      if (hues[i] < 0) continue;

      let merged2 = false;
      for (let j = 0; j < n; j++) {
        if (j === i || absorbed.has(j)) continue;
        const [bx2, by2, bw2, bh2] = blocks[j].bbox;
        if (bh2 > FIELD_HEIGHT_THRESHOLD) continue;
        if (hues[j] < 0) continue;

        // Même teinte
        let hueDiff = Math.abs(hues[i] - hues[j]);
        if (hueDiff > 90) hueDiff = 180 - hueDiff;
        if (hueDiff > 15) continue;

        // Même ligne verticale
        const yOvlp = Math.min(by1 + bh1, by2 + bh2) - Math.max(by1, by2);
        if (yOvlp < Math.min(bh1, bh2) * 0.4) continue;

        // À droite du fragment principal, avec un gap raisonnable
        const right1 = bx1 + bw1;
        if (bx2 < right1 - 5) continue;  // doit être à droite (ou légèrement chevauchant)
        if (bx2 - right1 > 120) continue;

        orInPlace(finalMasks[i], finalMasks[j]);
        absorbed.add(j);
        merged2 = true;
      }

      if (merged2) {
        let mnx = w, mny = h, mxx = -1, mxy = -1;
        for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
          if (!finalMasks[i][y * w + x]) continue;
          if (x < mnx) mnx = x; if (y < mny) mny = y;
          if (x > mxx) mxx = x; if (y > mxy) mxy = y;
        }
        if (mxx >= 0) blocks[i].bbox = [mnx, mny, mxx - mnx + 1, mxy - mny + 1];
      }
    }

    // Passe 3 : absorber tout petit bloc (< 40px) adjacent sur la même ligne
    // quelle que soit sa teinte (pills blancs "99.99", champs nombre, etc.)
    for (let i = 0; i < n; i++) {
      if (absorbed.has(i)) continue;
      const [bx1, by1, bw1, bh1] = blocks[i].bbox;
      if (bh1 > FIELD_HEIGHT_THRESHOLD) continue;
      if (bw1 < 60) continue; // doit être un bloc principal (pas un fragment orphelin)

      let merged3 = false;
      for (let j = 0; j < n; j++) {
        if (j === i || absorbed.has(j)) continue;
        const [bx2, by2, bw2, bh2] = blocks[j].bbox;
        if (bh2 > FIELD_HEIGHT_THRESHOLD) continue;
        if (bw2 >= 40) continue; // seuls les petits blocs (pills)

        // Même ligne verticale
        const yOvlp = Math.min(by1 + bh1, by2 + bh2) - Math.max(by1, by2);
        if (yOvlp < Math.min(bh1, bh2) * 0.3) continue;

        // Adjacent (gap < 30px)
        const right1 = bx1 + bw1, right2 = bx2 + bw2;
        const gap = Math.max(bx2 - right1, bx1 - right2);
        if (gap > 30) continue;

        orInPlace(finalMasks[i], finalMasks[j]);
        absorbed.add(j);
        merged3 = true;
      }

      if (merged3) {
        let mnx = w, mny = h, mxx = -1, mxy = -1;
        for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
          if (!finalMasks[i][y * w + x]) continue;
          if (x < mnx) mnx = x; if (y < mny) mny = y;
          if (x > mxx) mxx = x; if (y > mxy) mxy = y;
        }
        if (mxx >= 0) blocks[i].bbox = [mnx, mny, mxx - mnx + 1, mxy - mny + 1];
      }
    }

    if (absorbed.size === 0) return { blocks, finalMasks };

    const newBlocks = [], newMasks = [];
    for (let i = 0; i < n; i++) {
      if (absorbed.has(i)) continue;
      newBlocks.push(blocks[i]);
      newMasks.push(finalMasks[i]);
    }
    return { blocks: newBlocks, finalMasks: newMasks };
  }


  function detectSameColorDiamonds(blocks, finalMasks, rgba, hsvS, bgColor, w, h) {
    const n = blocks.length;
    const newBlocks = [], newMasks = [];
    const COVERAGE_RATIO = 0.70;

    for (let i = 0; i < n; i++) {
      const [xB, yB, bw, bh] = blocks[i].bbox;
      if (bh <= CONTAINER_HEIGHT_THRESHOLD) continue;
      const raw = blocks[i].rawMask;

      // Body right
      const bodyRightEdges = [];
      for (let y = yB + bh - 1; y > yB; y--) {
        let lastCol = -1;
        for (let x = w - 1; x >= 0; x--) { if (raw[y * w + x]) { lastCol = x; break; } }
        if (lastCol >= 0 && lastCol < xB + 100) bodyRightEdges.push(lastCol);
        if (bodyRightEdges.length > 10) break;
      }
      if (!bodyRightEdges.length) continue;
      const bodyRight = Math.max(...bodyRightEdges);

      // Condition bar
      const WIDE_THRESHOLD = bodyRight + 50;
      let inCbar = false, cbarTop = null, cbarBot = null;
      for (let y = yB; y < yB + bh; y++) {
        let lastCol = -1;
        for (let x = w - 1; x >= 0; x--) { if (raw[y * w + x]) { lastCol = x; break; } }
        const isWide = lastCol > WIDE_THRESHOLD;
        if (isWide && !inCbar) { cbarTop = y; inCbar = true; }
        else if (!isWide && inCbar) { cbarBot = y - 1; break; }
      }
      if (cbarTop === null || cbarBot === null) continue;

      // ── Exclure les chapeaux (hat blocks) ──
      // Un chapeau a une courbe convexe au-dessus de la condition bar :
      // les rangées s'élargissent progressivement du sommet vers la cbar.
      // Un vrai conteneur avec diamant a soit 0 rangées au-dessus (le bloc
      // commence directement par la cbar) soit une encoche étroite constante.
      {
        let firstOpaqueY = yB + bh; // default: no opaque row
        for (let y = yB; y < cbarTop; y++) {
          for (let x = 0; x < w; x++) {
            if (raw[y * w + x]) { firstOpaqueY = y; break; }
          }
          if (firstOpaqueY <= y) break;
        }
        const rowsAbove = cbarTop - firstOpaqueY;
        // S'il y a des rangées au-dessus, vérifier si c'est une courbe de chapeau
        if (rowsAbove >= 5) {
          // Mesurer les largeurs des rangées au-dessus de cbarTop
          const widths = [];
          for (let y = firstOpaqueY; y < cbarTop; y++) {
            let first = -1, last = -1;
            for (let x = 0; x < w; x++) {
              if (raw[y * w + x]) { if (first < 0) first = x; last = x; }
            }
            if (first >= 0) widths.push(last - first + 1);
          }
          // Courbe de chapeau : la largeur croît quasi-monotoniquement
          // et la première rangée est bien plus étroite que la dernière
          if (widths.length >= 5) {
            const firstW = widths[0];
            const lastW = widths[widths.length - 1];
            // Le chapeau : la première rangée fait < 50% de la dernière
            // et la croissance est progressive (≥ 70% des rangées croissent)
            let growCount = 0;
            for (let k = 1; k < widths.length; k++) {
              if (widths[k] >= widths[k - 1] - 1) growCount++;
            }
            const isGrowing = growCount / (widths.length - 1) >= 0.7;
            const isNarrowStart = firstW < lastW * 0.5;
            if (isGrowing && isNarrowStart) continue; // chapeau → skip
          }
        }
      }

      // Sous-blocs dans la condition bar
      const subInCbar = [];
      for (let j = 0; j < n; j++) {
        if (j === i) continue;
        const [ox, oy, ow, oh] = blocks[j].bbox;
        const ocy = oy + (oh >> 1);
        if (cbarTop <= ocy && ocy <= cbarBot && ox > bodyRight) subInCbar.push(j);
      }

      // Couverture
      const centerY = (cbarTop + cbarBot) >> 1;
      let lastColCenter = -1;
      for (let x = w - 1; x >= 0; x--) { if (raw[centerY * w + x]) { lastColCenter = x; break; } }
      if (lastColCenter < 0) continue;
      const extension = lastColCenter - bodyRight;

      // Vérifier la structure de condition bar même sans sous-blocs :
      // les sous-blocs (dropdown, pill) peuvent avoir été fusionnés avec le
      // conteneur parent en P3 (même teinte magenta). La condition bar est
      // valide si elle est assez large (extension > 50px).
      if (extension <= 50) continue;

      if (subInCbar.length > 0) {
        const totalSubW = subInCbar.reduce((s, j) => s + blocks[j].bbox[2], 0);
        if (totalSubW / extension >= COVERAGE_RATIO) continue;
      }

      // Reconstruire le diamant hexagonal
      const xLeft = bodyRight + 1, xRight = lastColCenter;
      const diamondMask = new Uint8Array(w * h);
      for (let y = cbarTop; y <= cbarBot; y++) {
        const yOff = Math.abs(y - centerY);
        const rLeft = xLeft + yOff, rRight = xRight - yOff;
        if (rLeft < rRight) {
          for (let x = rLeft; x <= rRight; x++) diamondMask[y * w + x] = 255;
        }
      }

      // Croiser avec pixels réels
      const bgR = bgColor[0], bgG = bgColor[1], bgB = bgColor[2];
      for (let idx = 0; idx < w * h; idx++) {
        if (!diamondMask[idx]) continue;
        const pSat = hsvS[idx];
        const o = idx * 4;
        const bgDist = Math.sqrt((rgba[o] - bgR) ** 2 + (rgba[o + 1] - bgG) ** 2 + (rgba[o + 2] - bgB) ** 2);
        if (pSat <= 25 && bgDist <= 30) diamondMask[idx] = 0;
      }

      // Fusionner les sous-blocs de la condition bar
      const absorbedIndices = new Set(subInCbar);
      for (const j of subInCbar) orInPlace(diamondMask, finalMasks[j]);

      let mnx = w, mny = h, mxx = -1, mxy = -1;
      for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
        if (!diamondMask[y * w + x]) continue;
        if (x < mnx) mnx = x; if (y < mny) mny = y;
        if (x > mxx) mxx = x; if (y > mxy) mxy = y;
      }
      if (mxx < 0) continue;

      newBlocks.push({
        rawMask: diamondMask,
        bbox: [mnx, mny, mxx - mnx + 1, mxy - mny + 1],
        area: countNonZero(diamondMask),
        meanColor: blocks[i].meanColor,
        meanHue: blocks[i].meanHue,
        regionCount: 1,
        isDiamond: true,
        _absorbed: absorbedIndices
      });
      newMasks.push(diamondMask);
    }

    if (!newBlocks.length) return { blocks, finalMasks };

    const allAbsorbed = new Set();
    for (const db of newBlocks) {
      if (db._absorbed) { for (const idx of db._absorbed) allAbsorbed.add(idx); delete db._absorbed; }
    }

    const allBlocks = [], allMasks = [];
    for (let i = 0; i < n; i++) {
      if (!allAbsorbed.has(i)) { allBlocks.push(blocks[i]); allMasks.push(finalMasks[i]); }
    }
    allBlocks.push(...newBlocks); allMasks.push(...newMasks);

    // Re-trier
    const order = allBlocks.map((_, i) => i).sort((a, b) =>
      allBlocks[a].bbox[1] - allBlocks[b].bbox[1] || allBlocks[a].bbox[0] - allBlocks[b].bbox[0]);
    return {
      blocks: order.map(i => allBlocks[i]),
      finalMasks: order.map(i => allMasks[i])
    };
  }

  // ═══════════════════════════════════════════════════════════════════
  // DÉTECTION AUTOMATIQUE DU TYPE D'IMAGE
  // ═══════════════════════════════════════════════════════════════════

  /**
   * Détermine si l'image est un programme en blocs (MakeCode/Scratch)
   * ou un algorigramme/flowchart.
   *
   * Critère : les blocs sont très colorés (saturation élevée),
   * les algorigrammes sont quasi monochromes (traits gris/noirs sur fond blanc).
   */
  function detectImageType(hsvS, w, h) {
    const n = w * h;
    let highSat = 0;
    for (let i = 0; i < n; i++) {
      if (hsvS[i] > 50) highSat++;
    }
    const ratio = highSat / n;
    // Blocs MakeCode/Scratch : > 5 % de pixels saturés
    // Algorigrammes : typiquement < 3 %
    return ratio > 0.04 ? 'blocks' : 'flowchart';
  }

  // ═══════════════════════════════════════════════════════════════════
  // EXTRACTION FLOWCHART (algorigramme)
  // ═══════════════════════════════════════════════════════════════════

  /**
   * Binarise l'image en niveaux de gris avec fermeture morphologique.
   * Retourne un masque binaire (Uint8Array) des pixels sombres.
   */
  function binarizeFlowchart(gray, w, h, thresh) {
    const bin = threshold(gray, w, h, thresh);
    // On veut les pixels SOMBRES → inverser
    const inv = bitwiseNot(bin, w, h);
    return morphClose(inv, w, h, 3, 3);
  }

  /**
   * Trouve les formes fermées d'un flowchart.
   * Stratégie : les formes sont des zones CLAIRES enfermées par des traits sombres.
   * On cherche les composantes connexes de l'inverse (pixels clairs) qui ne touchent
   * pas le bord de l'image → ce sont les intérieurs des formes.
   */
  function findClosedShapes(binary, gray, w, h) {
    const n = w * h;

    // Inverse du binaire : pixels clairs = 255
    const inv = bitwiseNot(binary, w, h);

    // Composantes connexes des zones claires
    const cc = connectedComponents(inv, w, h);
    const shapes = [];

    for (let lbl = 1; lbl < cc.numLabels; lbl++) {
      const st = cc.stats[lbl];
      if (st.area < 800) continue;
      if (st.width > w * 0.6 && st.height > h * 0.6) continue;

      // Exclure les composantes qui touchent le bord (= fond extérieur)
      if (st.left === 0 || st.top === 0 ||
          st.left + st.width >= w || st.top + st.height >= h) continue;

      // Filtrer les corridors verticaux entre formes
      if (st.height > st.width * 1.8) continue;
      const bboxArea = st.width * st.height;
      const solidity = bboxArea > 0 ? st.area / bboxArea : 0;
      if (solidity < 0.35) continue;

      const innerMask = maskFromLabel(cc.labels, lbl, n);

      shapes.push({
        innerMask,
        bbox: [st.left, st.top, st.width, st.height],
        area: st.area, solidity
      });
    }
    return shapes;
  }

  /**
   * Extrait le texte à l'intérieur d'une forme de flowchart.
   * innerMask = la zone blanche intérieure (SANS les pixels de texte/bordure).
   * On morphClose l'innerMask pour combler les trous du texte,
   * puis on cherche les pixels sombres dans cette zone remplie.
   */
  function extractShapeText(gray, innerMask, bbox, w, h) {
    const n = w * h;

    // filled = morphClose de innerMask → remplit les trous de texte
    // Deux passes : 9×9 standard + 21×1 horizontal pour combler les bordures
    // de losanges qui coupent l'intérieur en deux verticalement (~17px gap).
    const closed1 = morphClose(innerMask, w, h, 9, 9);
    const filled = morphClose(closed1, w, h, 21, 1); // pont horizontal

    // Zone sûre = filled érodé de 3px (exclut les bords de forme)
    const safeZone = erode(filled, w, h, 7, 7);
    let safeCount = 0;
    for (let i = 0; i < n; i++) if (safeZone[i]) safeCount++;
    if (safeCount < 30) return null;

    // Fond = 95ème percentile des gris clairs dans safeZone
    const safeGrays = [];
    for (let i = 0; i < n; i++) if (safeZone[i]) safeGrays.push(gray[i]);
    safeGrays.sort((a, b) => a - b);
    const bgVal = safeGrays[Math.floor(safeGrays.length * 0.95)] || 250;

    // Noyau de texte = pixels sombres dans la zone filled (pas uniquement
    // safeZone), mais en excluant les pixels de bord de forme.
    // On utilise filled (plus large) pour ne pas rater le texte dans les
    // losanges, puis on filtre les bordures avec un erode léger.
    const textZone = erode(filled, w, h, 3, 3);
    const textCore = new Uint8Array(n);
    const coreThresh = bgVal - 30;
    for (let i = 0; i < n; i++) {
      if (textZone[i] && gray[i] < coreThresh) textCore[i] = 255;
    }

    // Dilater 5×5 pour capturer l'antialiasing
    const textExpanded = dilate(textCore, w, h, 5, 5);

    // Masque final : dilaté ∩ filled ∩ pas trop clair
    const textMask = new Uint8Array(n);
    const lightThresh = bgVal - 8;
    for (let i = 0; i < n; i++) {
      if (textExpanded[i] && filled[i] && gray[i] < lightThresh) textMask[i] = 255;
    }

    // Filtrer petites composantes
    const cc = connectedComponents(textMask, w, h);
    const clean = new Uint8Array(n);
    for (let lbl = 1; lbl < cc.numLabels; lbl++) {
      if (cc.stats[lbl].area >= 8) {
        for (let i = 0; i < n; i++) if (cc.labels[i] === lbl) clean[i] = 255;
      }
    }

    // Bounding box du texte
    let tx1 = w, ty1 = h, tx2 = -1, ty2 = -1;
    for (let y = 0; y < h; y++) {
      for (let x = 0; x < w; x++) {
        if (!clean[y * w + x]) continue;
        if (x < tx1) tx1 = x; if (y < ty1) ty1 = y;
        if (x > tx2) tx2 = x; if (y > ty2) ty2 = y;
      }
    }
    if (tx2 < 0 || (tx2 - tx1 + 1) < 10) return null;

    // Masque texte-only : clean dilaté de 3px pour bordure blanche antialiasing
    const textHalo = dilate(clean, w, h, 7, 7);
    const rectMask = new Uint8Array(n);
    for (let i = 0; i < n; i++) {
      if (textHalo[i] && filled[i]) rectMask[i] = 255;
    }

    // Recalculer la bbox sur le rectMask final
    let rx1 = w, ry1 = h, rx2 = -1, ry2 = -1;
    for (let y = 0; y < h; y++) {
      for (let x = 0; x < w; x++) {
        if (!rectMask[y * w + x]) continue;
        if (x < rx1) rx1 = x; if (y < ry1) ry1 = y;
        if (x > rx2) rx2 = x; if (y > ry2) ry2 = y;
      }
    }

    return {
      rectMask,
      textBbox: [rx1, ry1, rx2 - rx1 + 1, ry2 - ry1 + 1],
      textCenter: [(rx1 + rx2) / 2, (ry1 + ry2) / 2],
      textCoreCount: countNonZero(textCore)
    };
  }

  /**
   * Pipeline complète d'extraction flowchart.
   */
  function extractFlowchart(imageData) {
    const w = imageData.width, h = imageData.height;
    const rgba = imageData.data;
    const gray = toGray(rgba, w, h);
    const n = w * h;

    // 1. Binariser et trouver les formes fermées
    const binary = binarizeFlowchart(gray, w, h, 200);
    const rawShapes = findClosedShapes(binary, gray, w, h);

    // 2. Extraire le texte de chaque forme
    const shapes = [];
    for (const shape of rawShapes) {
      const textInfo = extractShapeText(gray, shape.innerMask, shape.bbox, w, h);
      const [sx, sy, sw, sh] = shape.bbox;
      if (!textInfo) continue;

      const [tx, ty, tw, th] = textInfo.textBbox;

      // Filtrer texte vertical (faux positif sur chemins verticaux)
      if (th > tw * 2.5) continue;

      // Filtrer les formes très allongées verticalement (chemins, pas des formes)
      if (sh > sw * 3) continue;

      // Compter les vrais pixels de texte (sombres) dans le rectMask
      let textPixels = 0;
      for (let i = 0; i < n; i++) {
        if (textInfo.rectMask[i] && gray[i] < 180) textPixels++;
      }
      
      // Filtrage par densité de texte : vrais textes ≥ 1.5%, gaps < 1%
      // Utiliser l'area réelle de la forme (pas la bbox) pour les losanges
      const shapeArea = shape.area || (sw * sh);
      const textCoreDensity = shapeArea > 0 ? textInfo.textCoreCount / shapeArea : 0;
      if (textPixels < 50 || textCoreDensity < 0.012) continue;

      shapes.push({
        bbox: shape.bbox,
        innerMask: shape.innerMask,
        rectMask: textInfo.rectMask,
        textBbox: textInfo.textBbox,
        textCenter: textInfo.textCenter
      });
    }

    // 3. Trier de haut en bas
    shapes.sort((a, b) => a.textCenter[1] - b.textCenter[1]);

    // 4. Taille uniforme des étiquettes
    let maxTW = 0, maxTH = 0;
    for (const s of shapes) {
      if (s.textBbox[2] > maxTW) maxTW = s.textBbox[2];
      if (s.textBbox[3] > maxTH) maxTH = s.textBbox[3];
    }
    const pad = 6;
    const labelW = maxTW + pad * 2;
    const labelH = maxTH + pad * 2;

    // 5. Construire les masques des étiquettes et les positions
    const labelMasks = [];
    const labelPositions = [];

    for (let i = 0; i < shapes.length; i++) {
      const s = shapes[i];
      const lx = Math.round(s.textCenter[0] - labelW / 2);
      const ly = Math.round(s.textCenter[1] - labelH / 2);
      labelPositions.push({ x: lx, y: ly });
      labelMasks.push(s.rectMask);
    }

    // 6. Manifest
    const manifest = {
      source: 'canvas',
      imageType: 'flowchart',
      size: { w, h },
      labelSize: { w: labelW, h: labelH },
      labels: []
    };

    for (let i = 0; i < shapes.length; i++) {
      manifest.labels.push({
        id: i,
        pos: { x: labelPositions[i].x, y: labelPositions[i].y },
        size: { w: labelW, h: labelH },
        textBbox: {
          x: shapes[i].textBbox[0], y: shapes[i].textBbox[1],
          w: shapes[i].textBbox[2], h: shapes[i].textBbox[3]
        }
      });
    }

    return { manifest, labelMasks };
  }

  // ═══════════════════════════════════════════════════════════════════
  // EXTRACTION BLOCS (MakeCode / Scratch)
  // ═══════════════════════════════════════════════════════════════════
  function extractBlocks(imageData, srcInfo) {
    const w = imageData.width, h = imageData.height;
    const rgba = imageData.data;
    const phases = {};

    const gray = toGray(rgba, w, h);
    const hsv = toHSV(rgba, w, h);

    // Phase 1
    const borderMask = detectBorders(gray, w, h);
    phases.p1_border_nonzero = countNonZero(borderMask);

    // Phase 2
    const { regions, bgColor } = findRegions(rgba, hsv.H, hsv.S, hsv.V, borderMask, w, h);
    phases.p2_n_regions = regions.length;
    phases.p2_n_block_regions = regions.filter(r => !r.isBackground).length;
    phases.p2_bg_color = bgColor.map(Math.round);

    // Phase 3
    let blocks = mergeRegionsToBlocks(regions, w, h);
    phases.p3_n_blocks = blocks.length;
    phases.p3_bboxes = blocks.map(b => [...b.bbox]);

    // Phase 3b
    const nContainers = spanFillContainers(blocks, w);
    phases.p3b_n_containers = nContainers;

    // Phase 4
    let finalMasks = buildFinalMasks(blocks, rgba, hsv.H, hsv.S, hsv.V, borderMask, bgColor, w, h);
    phases.p4_n_masks = finalMasks.length;
    phases.p4_mask_nonzeros = finalMasks.map(countNonZero);

    // Phase 4j0 : fusion fragments horizontaux (dropdowns)
    phases.p4j0_before = blocks.length;
    {
      const r4j0 = mergeHorizontalFragments(blocks, finalMasks, rgba, w, h);
      blocks = r4j0.blocks; finalMasks = r4j0.finalMasks;
    }
    phases.p4j0_after = blocks.length;

    // Phase 4j
    phases.p4j_before = blocks.length;
    const r4j = mergeSubElements(blocks, finalMasks, w, h);
    blocks = r4j.blocks; finalMasks = r4j.finalMasks;
    phases.p4j_after = blocks.length;
    phases.p4j_bboxes = blocks.map(b => [...b.bbox]);

    // Phase 4k : détection diamonds même couleur que conteneur
    phases.p4k_before = blocks.length;
    {
      const r4k = detectSameColorDiamonds(blocks, finalMasks, rgba, hsv.S, bgColor, w, h);
      blocks = r4k.blocks; finalMasks = r4k.finalMasks;
    }
    phases.p4k_after = blocks.length;

    // Phase 4m : soustraire les enfants des conteneurs parents
    // 1) Soustraire le masque exact de chaque enfant
    // 2) Dans la bbox de l'enfant, soustraire TOUS les pixels non-conteneur
    //    (texte blanc/sombre des enfants, antialiasing, résidus)
    // 3) Dans un halo autour de la bbox, soustraire les pixels non-conteneur
    const nn = w * h;
    const CONTAIN_TOL = 8; // tolérance px pour le test de containment
    for (let ci = 0; ci < blocks.length; ci++) {
      const [cx, cy, cw, ch] = blocks[ci].bbox;
      if (ch <= CONTAINER_HEIGHT_THRESHOLD) continue;
      const containerColor = blocks[ci].meanColor; // [R, G, B]
      const containerHue = blocks[ci].meanHue;
      const cmask = finalMasks[ci];

      for (let bi = 0; bi < blocks.length; bi++) {
        if (bi === ci) continue;
        const [bx, by, bw, bh] = blocks[bi].bbox;
        // Test de containment avec tolérance : l'enfant doit être
        // globalement à l'intérieur du conteneur (± CONTAIN_TOL px)
        if (bx < cx - CONTAIN_TOL || by < cy - CONTAIN_TOL ||
            bx + bw > cx + cw + CONTAIN_TOL || by + bh > cy + ch + CONTAIN_TOL) continue;
        // Vérifier aussi qu'il y a un chevauchement réel avec le masque conteneur
        // (éviter les faux positifs avec des blocs juste à côté)
        let overlap = 0;
        const testY1 = Math.max(0, by), testY2 = Math.min(h, by + bh);
        const testX1 = Math.max(0, bx), testX2 = Math.min(w, bx + bw);
        for (let y = testY1; y < testY2; y++) {
          for (let x = testX1; x < testX2; x++) {
            if (cmask[y * w + x]) { overlap++; if (overlap > 5) break; }
          }
          if (overlap > 5) break;
        }
        if (overlap === 0) continue;

        const bmask = finalMasks[bi];
        // Étape 1 : soustraire le masque exact de l'enfant
        for (let p = 0; p < nn; p++) {
          if (bmask[p]) cmask[p] = 0;
        }

        // Étape 2 : dans la bbox de l'enfant, soustraire TOUS les pixels
        // qui ne sont PAS de la couleur du conteneur. Ceci enlève le texte
        // blanc/sombre des blocs enfants qui n'est pas dans leur masque.
        const innerY1 = Math.max(0, by + 1), innerY2 = Math.min(h, by + bh - 1);
        const innerX1 = Math.max(0, bx + 1), innerX2 = Math.min(w, bx + bw - 1);
        for (let y = innerY1; y < innerY2; y++) {
          for (let x = innerX1; x < innerX2; x++) {
            const p = y * w + x;
            if (!cmask[p]) continue;
            const o = p * 4;
            const pr = rgba[o], pg = rgba[o + 1], pb = rgba[o + 2];
            const dr = pr - containerColor[0], dg = pg - containerColor[1], db = pb - containerColor[2];
            const dist = Math.sqrt(dr * dr + dg * dg + db * db);
            if (dist > 45) { cmask[p] = 0; continue; }
            // Vérifier aussi la teinte pour les pixels saturés
            const pSat = hsv.S[p];
            if (pSat > 50) {
              let hd = Math.abs(hsv.H[p] - containerHue);
              hd = Math.min(hd, 180 - hd);
              if (hd > 15) cmask[p] = 0;
            }
          }
        }

        // Étape 3 : dans un halo autour de la bbox, soustraire les pixels non-conteneur
        const margin = EXTRACTION_PAD + 2;
        const ey1 = Math.max(0, by - margin), ey2 = Math.min(h, by + bh + margin);
        const ex1 = Math.max(0, bx - margin), ex2 = Math.min(w, bx + bw + margin);
        for (let y = ey1; y < ey2; y++) {
          for (let x = ex1; x < ex2; x++) {
            // Skip la zone interne déjà traitée
            if (y >= innerY1 && y < innerY2 && x >= innerX1 && x < innerX2) continue;
            const p = y * w + x;
            if (!cmask[p]) continue;
            const o = p * 4;
            const pr = rgba[o], pg = rgba[o + 1], pb = rgba[o + 2];
            const dr = pr - containerColor[0], dg = pg - containerColor[1], db = pb - containerColor[2];
            const dist = Math.sqrt(dr * dr + dg * dg + db * db);
            if (dist > 50) cmask[p] = 0;
          }
        }
      }
    }

    // Phase 5 : Manifest
    // Filtrer les blocs tronqués (bouts de blocs coupés par le bord de la capture)
    const MIN_BLOCK_HEIGHT = 25;
    {
      const validIdx = [];
      for (let i = 0; i < blocks.length; i++) {
        if (blocks[i].bbox[3] < MIN_BLOCK_HEIGHT) continue;
        validIdx.push(i);
      }
      if (validIdx.length < blocks.length) {
        blocks = validIdx.map(i => blocks[i]);
        finalMasks = validIdx.map(i => finalMasks[i]);
      }
    }

    // ── P6 : Manifest ────────────────────────────────────────────────
    const manifest = {
      source: 'makecode',
      imageType: 'blocks',
      size: { w, h },
      blocks: []
    };

    for (let i = 0; i < blocks.length; i++) {
      const [bx, by, bw, bh] = blocks[i].bbox;
      const pad = EXTRACTION_PAD;
      const x1 = Math.max(0, bx - pad), y1 = Math.max(0, by - pad);
      const x2 = Math.min(w, bx + bw + pad), y2 = Math.min(h, by + bh + pad);

      let blockType;
      if (blocks[i].isDiamond) blockType = 'diamond';
      else if (bh > CONTAINER_HEIGHT_THRESHOLD) blockType = 'container';
      else if (bh <= FIELD_HEIGHT_THRESHOLD && bw > DIAMOND_MIN_WIDTH) blockType = 'diamond';
      else blockType = 'block';

      manifest.blocks.push({
        id: i,
        pos: { x: x1, y: y1 },
        size: { w: x2 - x1, h: y2 - y1 },
        color_bgr: [
          Math.round(blocks[i].meanColor[2]),
          Math.round(blocks[i].meanColor[1]),
          Math.round(blocks[i].meanColor[0])
        ],
        area: blocks[i].area,
        type: blockType
      });
    }

    phases.p5_blocks = manifest.blocks.map(b => ({
      bbox: [b.pos.x, b.pos.y, b.size.w, b.size.h],
      type: b.type,
      area: b.area,
      mask_nonzero: countNonZero(finalMasks[b.id])
    }));

    return { manifest, blockMasks: finalMasks, bgColor, phases, _version: '2026-08-07-MakeCode-blocs-imbriques' };
  }

  // ═══════════════════════════════════════════════════════════════════
  // EXTRACTION SCRATCH — pipeline dédié (v4)
  // ═══════════════════════════════════════════════════════════════════
  // Pipeline en 6 phases adapté aux captures Scratch :
  //   P1: Détection bordures (gradient morphologique)
  //   P2: Régions connexes + classification (bg / block / internal)
  //   P3: Fusion blocs par teinte + adjacence (union-find)
  //   P3b: Absorption par containment (drapeau, dropdowns, paramètres)
  //   P4: Absorption des régions internes (texte, bulles) + bordures
  //   P5: Fill external → masques solides + nettoyage résidus couleur
  //   P6: Extraction individuelle → manifest
  // ═══════════════════════════════════════════════════════════════════
  function extractScratch(imageData) {
    const w = imageData.width, h = imageData.height;
    const rgba = imageData.data;
    const n = w * h;
    const phases = {};

    const gray = toGray(rgba, w, h);
    const hsv = toHSV(rgba, w, h);
    const hH = hsv.H, hS = hsv.S, hV = hsv.V;

    // ── P1 : Détection bordures ──────────────────────────────────────
    const grad = morphGradient(gray, w, h, 3, 3);
    const borderTh = threshold(grad, w, h, 5);
    const borderMask = morphClose(borderTh, w, h, 2, 2);
    phases.p1_border_nonzero = countNonZero(borderMask);

    // ── P2 : Composantes connexes + classification ───────────────────
    const fillable = bitwiseNot(borderMask, w, h);
    const cc = connectedComponents(fillable, w, h);

    // Labels de fond (coins)
    const bgLabels = new Set();
    for (const [cy, cx] of [[2, 2], [2, w - 3], [h - 3, 2], [h - 3, w - 3]]) {
      if (cy >= 0 && cy < h && cx >= 0 && cx < w) {
        const l = cc.labels[cy * w + cx];
        if (l > 0) bgLabels.add(l);
      }
    }

    // Couleur de fond moyenne
    let bgR = 0, bgG = 0, bgB = 0, bgC = 0;
    for (let i = 0; i < n; i++) {
      if (!bgLabels.has(cc.labels[i])) continue;
      bgR += rgba[i * 4]; bgG += rgba[i * 4 + 1]; bgB += rgba[i * 4 + 2]; bgC++;
    }
    const bgColor = bgC ? [bgR / bgC, bgG / bgC, bgB / bgC] : [249, 249, 249];
    phases.p2_bg_color = bgColor.map(Math.round);

    // Classification des régions : bg / block / internal
    const SCRATCH_MIN_AREA = 12;
    const regions = [];
    for (let l = 1; l < cc.numLabels; l++) {
      const st = cc.stats[l];
      if (st.area < SCRATCH_MIN_AREA) continue;

      let sr = 0, sg = 0, sb = 0, cnt = 0, sH = 0, sS = 0, sV = 0, satCnt = 0;
      for (let y = st.top; y < st.top + st.height && y < h; y++) {
        for (let x = st.left; x < st.left + st.width && x < w; x++) {
          const i = y * w + x;
          if (cc.labels[i] !== l) continue;
          const o = i * 4;
          sr += rgba[o]; sg += rgba[o + 1]; sb += rgba[o + 2]; cnt++;
          sS += hS[i]; sV += hV[i];
          if (hS[i] > 40) { sH += hH[i]; satCnt++; }
        }
      }
      if (!cnt) continue;

      const meanR = sr / cnt, meanG = sg / cnt, meanB = sb / cnt;
      const meanSat = sS / cnt, meanVal = sV / cnt;
      const meanHue = satCnt > 0 ? sH / satCnt : 0;
      const colorDist = Math.sqrt(
        (meanR - bgColor[0]) ** 2 + (meanG - bgColor[1]) ** 2 + (meanB - bgColor[2]) ** 2
      );

      let type;
      if (bgLabels.has(l)) { type = 'bg'; }
      else if (meanSat > 35) { type = 'block'; }
      else if (colorDist < 30 && meanSat < 25) { type = 'bg'; }
      else { type = 'internal'; }

      regions.push({
        label: l, area: st.area,
        bbox: [st.left, st.top, st.width, st.height],
        meanColor: [meanR, meanG, meanB], meanHue, meanSat, meanVal, type
      });
    }
    phases.p2_n_regions = regions.length;
    phases.p2_n_block_regions = regions.filter(r => r.type === 'block').length;
    phases.p2_n_internal = regions.filter(r => r.type === 'internal').length;

    // ── P3 : Fusion des régions "block" par teinte + adjacence ───────
    const blockRegs = regions.filter(r => r.type === 'block');
    const nB = blockRegs.length;
    const bpar = Array.from({ length: nB }, (_, i) => i);
    function bfind(x) { while (bpar[x] !== x) { bpar[x] = bpar[bpar[x]]; x = bpar[x]; } return x; }
    function bunion(a, b) { a = bfind(a); b = bfind(b); if (a !== b) bpar[Math.max(a, b)] = Math.min(a, b); }

    // Masques individuels pour test d'adjacence
    const blockMasksP3 = blockRegs.map(r => {
      const m = new Uint8Array(n);
      const st = cc.stats[r.label];
      for (let y = st.top; y < st.top + st.height && y < h; y++)
        for (let x = st.left; x < st.left + st.width && x < w; x++) {
          const i = y * w + x;
          if (cc.labels[i] === r.label) m[i] = 255;
        }
      return m;
    });

    const SCRATCH_HUE_MERGE = 15;
    const SCRATCH_VAL_MERGE = 40;
    // La TEINTE ne sépare PAS les catégories Scratch : « si » (255,171,25),
    // « envoyer à tous » (240,180,0) et « ajouter à » (244,130,18) sont à 3,5-4 de
    // teinte les uns des autres, bien en dessous de SCRATCH_HUE_MERGE. Résultat, un
    // bloc posé dans un « si » était fusionné avec lui et jamais extrait.
    // Leur distance RGB, elle, vaut 30 à 43, alors qu'à l'intérieur d'un même bloc
    // le corps est d'une couleur parfaitement plate (distance ~0).
    const SCRATCH_COLOR_MERGE = 20;
    function scratchMemeCouleur(a, b) {
      const dr = a[0] - b[0], dg = a[1] - b[1], db = a[2] - b[2];
      return dr * dr + dg * dg + db * db <= SCRATCH_COLOR_MERGE * SCRATCH_COLOR_MERGE;
    }

    for (let i = 0; i < nB; i++) {
      const dil = dilate(blockMasksP3[i], w, h, 5, 5);
      for (let j = i + 1; j < nB; j++) {
        let hd = Math.abs(blockRegs[i].meanHue - blockRegs[j].meanHue);
        hd = Math.min(hd, 180 - hd);
        if (hd > SCRATCH_HUE_MERGE) continue;
        const vd = Math.abs(blockRegs[i].meanVal - blockRegs[j].meanVal);
        if (vd > SCRATCH_VAL_MERGE) continue;
        if (!scratchMemeCouleur(blockRegs[i].meanColor, blockRegs[j].meanColor)) continue;
        // Adjacence : le dilaté de i touche-t-il j ?
        let touches = false;
        const stj = cc.stats[blockRegs[j].label];
        for (let y = stj.top; y < stj.top + stj.height && y < h && !touches; y++)
          for (let x = stj.left; x < stj.left + stj.width && x < w && !touches; x++) {
            const idx = y * w + x;
            if (dil[idx] && blockMasksP3[j][idx]) touches = true;
          }
        if (touches) bunion(i, j);
      }
    }

    // ── P3 pass 2 : fusion par proximité de bbox (champs blancs internes) ──
    // Fragments d'un MÊME bloc coupé par un input blanc : bboxes quasi-
    // identiques en Y (tops et bottoms proches), gap X modéré.
    // Ne fusionne PAS les blocs empilés verticalement (Y décalé).
    const SCRATCH_PROX_X_GAP = 60;
    const SCRATCH_PROX_Y_TOLERANCE = 8; // tops et bottoms doivent être ≤8px
    for (let i = 0; i < nB; i++) {
      for (let j = i + 1; j < nB; j++) {
        if (bfind(i) === bfind(j)) continue;
        let hd = Math.abs(blockRegs[i].meanHue - blockRegs[j].meanHue);
        hd = Math.min(hd, 180 - hd);
        if (hd > SCRATCH_HUE_MERGE) continue;
        const vd = Math.abs(blockRegs[i].meanVal - blockRegs[j].meanVal);
        if (vd > SCRATCH_VAL_MERGE) continue;
        if (!scratchMemeCouleur(blockRegs[i].meanColor, blockRegs[j].meanColor)) continue;

        const [ax, ay, aw, ah] = blockRegs[i].bbox;
        const [bx, by, bw, bh] = blockRegs[j].bbox;
        // Tops et bottoms quasi-identiques (même ligne de bloc)
        // OU centres Y proches (fragments de hauteurs différentes, coins arrondis)
        const topClose = Math.abs(ay - by) <= SCRATCH_PROX_Y_TOLERANCE;
        const botClose = Math.abs((ay + ah) - (by + bh)) <= SCRATCH_PROX_Y_TOLERANCE;
        const centerClose = Math.abs((ay + ah / 2) - (by + bh / 2)) <= SCRATCH_PROX_Y_TOLERANCE;
        if (!(topClose && botClose) && !centerClose) continue;
        // Gap horizontal modéré
        const xGap = Math.max(0,
          Math.max(ax, bx) - Math.min(ax + aw, bx + bw));
        if (xGap > SCRATCH_PROX_X_GAP) continue;
        bunion(i, j);
      }
    }

    // Construire les groupes fusionnés
    const groups = {};
    for (let i = 0; i < nB; i++) {
      const root = bfind(i);
      if (!groups[root]) groups[root] = [];
      groups[root].push(i);
    }

    const mergedBlocks = [];
    for (const indices of Object.values(groups)) {
      const mask = new Uint8Array(n);
      let totalArea = 0;
      const allColors = [], allHues = [];
      for (const idx of indices) {
        const r = blockRegs[idx];
        const st = cc.stats[r.label];
        for (let y = st.top; y < st.top + st.height && y < h; y++)
          for (let x = st.left; x < st.left + st.width && x < w; x++) {
            const i = y * w + x;
            if (cc.labels[i] === r.label) mask[i] = 255;
          }
        totalArea += r.area;
        allColors.push(r.meanColor);
        allHues.push(r.meanHue);
      }
      let mnx = w, mny = h, mxx = -1, mxy = -1;
      for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
        if (!mask[y * w + x]) continue;
        if (x < mnx) mnx = x; if (y < mny) mny = y;
        if (x > mxx) mxx = x; if (y > mxy) mxy = y;
      }
      if (mxx < 0) continue;
      const mc = allColors.reduce((a, c) => [a[0] + c[0], a[1] + c[1], a[2] + c[2]], [0, 0, 0])
        .map(v => v / allColors.length);
      const mh = allHues.reduce((a, v) => a + v, 0) / allHues.length;
      mergedBlocks.push({
        mask, bbox: [mnx, mny, mxx - mnx + 1, mxy - mny + 1],
        area: totalArea, meanColor: mc, meanHue: mh,
        regionCount: indices.length
      });
    }
    mergedBlocks.sort((a, b) => a.bbox[1] - b.bbox[1] || a.bbox[0] - b.bbox[0]);
    phases.p3_n_merged = mergedBlocks.length;

    // ── P3b : Absorption par containment ─────────────────────────────
    {
      const absorbed = new Set();
      let changed = true;
      while (changed) {
        changed = false;
        for (let ci = 0; ci < mergedBlocks.length; ci++) {
          if (absorbed.has(ci)) continue;
          const [cx, cy, cw, ch] = mergedBlocks[ci].bbox;
          const cArea = cw * ch;

          let bestParent = -1, bestParentArea = Infinity;
          for (let pi = 0; pi < mergedBlocks.length; pi++) {
            if (pi === ci || absorbed.has(pi)) continue;
            const [px, py, pw, ph] = mergedBlocks[pi].bbox;
            const pArea = pw * ph;
            if (pArea <= cArea) continue;
            const margin = 4;
            if (cx >= px - margin && cy >= py - margin &&
              cx + cw <= px + pw + margin && cy + ch <= py + ph + margin) {
              if (pArea < bestParentArea) { bestParentArea = pArea; bestParent = pi; }
            }
          }

          if (bestParent >= 0) {
            const pm = mergedBlocks[bestParent].mask;
            const cm = mergedBlocks[ci].mask;
            for (let i = 0; i < n; i++) if (cm[i]) pm[i] = 255;
            mergedBlocks[bestParent].area += mergedBlocks[ci].area;
            mergedBlocks[bestParent].regionCount += mergedBlocks[ci].regionCount;
            const [px, py, pw, ph] = mergedBlocks[bestParent].bbox;
            const [cx2, cy2, cw2, ch2] = mergedBlocks[ci].bbox;
            const nx = Math.min(px, cx2), ny = Math.min(py, cy2);
            const nx2 = Math.max(px + pw, cx2 + cw2), ny2 = Math.max(py + ph, cy2 + ch2);
            mergedBlocks[bestParent].bbox = [nx, ny, nx2 - nx, ny2 - ny];
            absorbed.add(ci);
            changed = true;
          }
        }
      }

      if (absorbed.size > 0) {
        const newBlocks = [];
        for (let i = 0; i < mergedBlocks.length; i++) {
          if (!absorbed.has(i)) newBlocks.push(mergedBlocks[i]);
        }
        mergedBlocks.length = 0;
        mergedBlocks.push(...newBlocks);
      }
    }
    phases.p3b_after_absorption = mergedBlocks.length;

    // Ton DOMINANT d'un masque (et non moyen : la moyenne est faussée par les menus
    // déroulants, plus sombres, alors que le corps d'un bloc est parfaitement plat).
    function scratchTonDominant(mask) {
      const compte = new Map();
      for (let i = 0; i < n; i++) {
        if (!mask[i]) continue;
        const o = i * 4;
        const cle = ((rgba[o] >> 3) << 10) | ((rgba[o + 1] >> 3) << 5) | (rgba[o + 2] >> 3);
        compte.set(cle, (compte.get(cle) || 0) + 1);
      }
      let meilleure = -1, meilleurN = 0;
      for (const [cle, nb] of compte) if (nb > meilleurN) { meilleurN = nb; meilleure = cle; }
      if (meilleure < 0) return null;
      return [((meilleure >> 10) & 31) << 3, ((meilleure >> 5) & 31) << 3, (meilleure & 31) << 3];
    }

    // ── P3d : Complétion de la silhouette par la couleur ─────────────
    // Le corps d'un bloc Scratch qui ENTOURE un opérateur ne fait que 2 px de haut
    // (mesuré : « mettre … à () », bandes en y=53-54 et y=84-85). Le gradient
    // morphologique classe une bande de 2 px entièrement en « bordure » : elle ne
    // forme aucune composante connexe, le bloc s'arrêtait donc au bord de l'opérateur
    // et la pièce sortait TRONQUÉE (« mettre … à » coupé, bulle « 5 » manquante,
    // extrémité arrondie perdue), en laissant un fantôme sur le fond.
    // Ces pixels sont pourtant exactement de la couleur du bloc : on les récupère de
    // proche en proche, en bornant la propagation à la BANDE HORIZONTALE du bloc pour
    // ne jamais déborder sur un bloc voisin de même couleur empilé au-dessus/dessous.
    {
      // Tolérance SERRÉE : le corps d'un bloc Scratch est d'une couleur parfaitement
      // plate, seuls les bords anti-aliasés varient (et on n'en a pas besoin ici).
      // Mesuré : chapeau jaune (255,191,0) et « si » orange (255,171,25) ne sont
      // distants que de 32 — à 40, la propagation passait du chapeau au bloc du
      // dessous et le chapeau ressortait avec une bande sur toute la largeur.
      const SILHOUETTE_TOL = 20;   // distance RGB au ton dominant du bloc
      const SILHOUETTE_MARGE_Y = 2;
      for (const b of mergedBlocks) {
        const ton = scratchTonDominant(b.mask);
        if (!ton) continue;
        const cr = ton[0], cg = ton[1], cb = ton[2];

        const [bx, by, bw, bh] = b.bbox;
        const yMin = Math.max(0, by - SILHOUETTE_MARGE_Y);
        const yMax = Math.min(h - 1, by + bh - 1 + SILHOUETTE_MARGE_Y);

        const file = [];
        for (let y = yMin; y <= yMax; y++) {
          const base = y * w;
          for (let x = 0; x < w; x++) if (b.mask[base + x]) file.push(base + x);
        }
        for (let tete = 0; tete < file.length; tete++) {
          const i = file[tete];
          const x = i % w, y = (i - x) / w;
          for (let dy = -1; dy <= 1; dy++) {
            const ny = y + dy;
            if (ny < yMin || ny > yMax) continue;
            for (let dx = -1; dx <= 1; dx++) {
              const nx = x + dx;
              if (nx < 0 || nx >= w) continue;
              const j = ny * w + nx;
              if (b.mask[j]) continue;
              const o = j * 4;
              if (rgba[o + 3] === 0) continue;
              const dr = rgba[o] - cr, dg = rgba[o + 1] - cg, db = rgba[o + 2] - cb;
              if (dr * dr + dg * dg + db * db > SILHOUETTE_TOL * SILHOUETTE_TOL) continue;
              b.mask[j] = 255;
              file.push(j);
            }
          }
        }

        let mnx = w, mny = h, mxx = -1, mxy = -1;
        for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
          if (!b.mask[y * w + x]) continue;
          if (x < mnx) mnx = x; if (y < mny) mny = y;
          if (x > mxx) mxx = x; if (y > mxy) mxy = y;
        }
        if (mxx >= 0) b.bbox = [mnx, mny, mxx - mnx + 1, mxy - mny + 1];
      }
    }

    // ── P3c : Fusion par recouvrement (même teinte + overlap significatif) ──
    // Quand la gestion ICC du navigateur modifie les pixels, un bloc peut
    // être coupé en 2 composantes connexes qui ne sont ni adjacentes (P3)
    // ni l'une dans l'autre (P3b) mais qui se chevauchent fortement.
    {
      let changed = true;
      while (changed) {
        changed = false;
        for (let i = 0; i < mergedBlocks.length && !changed; i++) {
          for (let j = i + 1; j < mergedBlocks.length && !changed; j++) {
            let hd = Math.abs(mergedBlocks[i].meanHue - mergedBlocks[j].meanHue);
            hd = Math.min(hd, 180 - hd);
            if (hd > SCRATCH_HUE_MERGE) continue;
            const [ax, ay, aw, ah] = mergedBlocks[i].bbox;
            const [bx, by, bw, bh] = mergedBlocks[j].bbox;
            // Calcul de l'intersection
            const ox = Math.max(0, Math.min(ax + aw, bx + bw) - Math.max(ax, bx));
            const oy = Math.max(0, Math.min(ay + ah, by + bh) - Math.max(ay, by));
            const overlapArea = ox * oy;
            const minArea = Math.min(aw * ah, bw * bh);
            if (overlapArea < minArea * 0.25) continue;
            // Fusionner j dans i
            const mi = mergedBlocks[i].mask, mj = mergedBlocks[j].mask;
            for (let k = 0; k < n; k++) if (mj[k]) mi[k] = 255;
            const nx = Math.min(ax, bx), ny = Math.min(ay, by);
            const nx2 = Math.max(ax + aw, bx + bw), ny2 = Math.max(ay + ah, by + bh);
            mergedBlocks[i].bbox = [nx, ny, nx2 - nx, ny2 - ny];
            mergedBlocks[i].area += mergedBlocks[j].area;
            mergedBlocks[i].regionCount += mergedBlocks[j].regionCount;
            mergedBlocks.splice(j, 1);
            changed = true;
          }
        }
      }
    }
    phases.p3c_after_overlap = mergedBlocks.length;

    // ── P4 : Absorption des régions internes + bordures ──────────────
    const intRegs = regions.filter(r => r.type === 'internal');
    const p4Masks = mergedBlocks.map(b => new Uint8Array(b.mask));

    for (const ir of intRegs) {
      const [ix, iy, iw, ih] = ir.bbox;
      const icx = ix + iw / 2, icy = iy + ih / 2;

      let bestIdx = -1, bestArea = Infinity;
      for (let bi = 0; bi < mergedBlocks.length; bi++) {
        const [bx, by, bw, bh] = mergedBlocks[bi].bbox;
        const margin = 5;
        if (icx >= bx - margin && icx <= bx + bw + margin &&
          icy >= by - margin && icy <= by + bh + margin) {
          const bArea = bw * bh;
          if (bArea < bestArea) { bestArea = bArea; bestIdx = bi; }
        }
      }
      if (bestIdx < 0) continue;

      const st = cc.stats[ir.label];
      for (let y = st.top; y < st.top + st.height && y < h; y++)
        for (let x = st.left; x < st.left + st.width && x < w; x++) {
          const i = y * w + x;
          if (cc.labels[i] === ir.label) p4Masks[bestIdx][i] = 255;
        }
    }

    // Ajouter les pixels de bordure adjacents à chaque bloc
    for (let bi = 0; bi < p4Masks.length; bi++) {
      const dil = dilate(p4Masks[bi], w, h, 3, 3);
      for (let i = 0; i < n; i++) {
        if (borderMask[i] && dil[i]) p4Masks[bi][i] = 255;
      }
    }
    phases.p4_n_masks = p4Masks.length;

    // ── P5 : Fill external → masques solides ─────────────────────────
    const p5Masks = p4Masks.map(m => fillExternal(m, w, h));

    // ── P5b : Nettoyage résidus de couleur en bordure ────────────────
    // Supprime les pixels de teinte très différente du bloc qui sont sur
    // la bordure EXTERNE uniquement (3px d'épaisseur). On utilise le
    // masque fillExternal original pour identifier la bordure et on
    // ne fait qu'UNE passe (pas d'itération qui mangerait les diamants internes).
    let removedTotal = 0;
    for (let bi = 0; bi < p5Masks.length; bi++) {
      const fm = p5Masks[bi];
      const blockHue = mergedBlocks[bi].meanHue;
      const blockColor = mergedBlocks[bi].meanColor;
      const ero = erode(fm, w, h, 3, 3);

      for (let i = 0; i < n; i++) {
        if (!(fm[i] && !ero[i])) continue;
        const pS = hS[i], pH = hH[i];
        if (pS < 30) continue;
        let hd = Math.abs(pH - blockHue);
        hd = Math.min(hd, 180 - hd);
        const o = i * 4;
        const dr = rgba[o] - blockColor[0];
        const dg = rgba[o + 1] - blockColor[1];
        const db = rgba[o + 2] - blockColor[2];
        const colorDist = Math.sqrt(dr * dr + dg * dg + db * db);
        if (hd > 20 && colorDist > 60) {
          // Vérifier que ce pixel touche le VRAI extérieur du masque P5
          // (pas un trou interne temporaire créé par une suppression antérieure)
          const x = i % w, y = (i - x) / w;
          let touchesExterior = false;
          for (const [dx, dy] of [[-1,0],[1,0],[0,-1],[0,1],[-1,-1],[1,-1],[-1,1],[1,1]]) {
            const nx = x + dx, ny = y + dy;
            if (nx < 0 || nx >= w || ny < 0 || ny >= h) { touchesExterior = true; break; }
            if (!fm[ny * w + nx]) { touchesExterior = true; break; }
          }
          if (touchesExterior) {
            fm[i] = 0;
            removedTotal++;
          }
        }
      }
    }
    phases.p5b_removed_total = removedTotal;

    // Recalculer les bboxes après nettoyage
    const finalBlocks = [];
    const finalMasks = [];
    for (let bi = 0; bi < p5Masks.length; bi++) {
      let mnx = w, mny = h, mxx = -1, mxy = -1;
      for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
        if (!p5Masks[bi][y * w + x]) continue;
        if (x < mnx) mnx = x; if (y < mny) mny = y;
        if (x > mxx) mxx = x; if (y > mxy) mxy = y;
      }
      if (mxx < 0) continue;
      finalBlocks.push({
        bbox: [mnx, mny, mxx - mnx + 1, mxy - mny + 1],
        area: countNonZero(p5Masks[bi]),
        meanColor: mergedBlocks[bi].meanColor,
        meanHue: mergedBlocks[bi].meanHue
      });
      finalMasks.push(p5Masks[bi]);
    }

    // ── P6a : Extraction des enfants de teinte différente des conteneurs ─
    // Détecte les diamants (conditions), blocs internes de couleur différente
    // et les extrait comme blocs séparés, puis les soustrait du conteneur.
    {
      const CHILD_HUE_DIFF = 20;
      const CHILD_MIN_SAT = 35;
      const CHILD_MIN_SEED_AREA = 80;
      const CHILD_DILATE_K = 7;
      const CHILD_HUE_GROUP = 25;
      const childrenToAdd = [];

      for (let ci = 0; ci < finalBlocks.length; ci++) {
        const [, , , cbh] = finalBlocks[ci].bbox;
        if (cbh <= CONTAINER_HEIGHT_THRESHOLD) continue;

        const cMask = finalMasks[ci];
        const cHue = finalBlocks[ci].meanHue;

        // Pixels saturés de teinte différente du conteneur
        const seedPixels = new Uint8Array(n);
        let seedCount = 0;
        for (let i = 0; i < n; i++) {
          if (!cMask[i]) continue;
          if (hS[i] < CHILD_MIN_SAT) continue;
          let hd = Math.abs(hH[i] - cHue);
          hd = Math.min(hd, 180 - hd);
          if (hd > CHILD_HUE_DIFF) { seedPixels[i] = 255; seedCount++; }
        }
        if (seedCount < CHILD_MIN_SEED_AREA) continue;

        // Dilatation + CC pour regrouper les graines (par groupe de teinte)
        // Note : on dilate par sous-groupe de teinte ci-dessous pour éviter
        // de fusionner des régions de couleurs très différentes.

        // Construire les clusters
        // ── Sous-division par teinte AVANT CC ──
        // La dilatation peut fusionner physiquement des régions de teintes
        // très différentes (ex: bordure verte du diamant H=60 + bloc violet H=130).
        // Solution : re-séparer le seedDil en sous-masques par teinte, puis CC
        // sur chaque sous-masque séparément.

        // 1. Trouver les groupes de teinte parmi les seed pixels
        const hueGroups = []; // [{hue, mask}]
        for (let i = 0; i < n; i++) {
          if (!seedPixels[i]) continue;
          const ph = hH[i];
          let found = false;
          for (const g of hueGroups) {
            let hd = Math.abs(ph - g.hue);
            hd = Math.min(hd, 180 - hd);
            if (hd <= CHILD_HUE_GROUP) {
              g.pixels.push(i);
              g.hueSum += ph;
              g.cnt++;
              g.hue = g.hueSum / g.cnt; // running average
              found = true;
              break;
            }
          }
          if (!found) {
            hueGroups.push({ hue: ph, pixels: [i], hueSum: ph, cnt: 1 });
          }
        }

        // 2. Pour chaque groupe de teinte, créer un sous-masque dilaté et faire CC
        const clusters = [];
        for (const grp of hueGroups) {
          if (grp.cnt < CHILD_MIN_SEED_AREA) continue;
          // Sous-masque de seeds de cette teinte
          const subSeed = new Uint8Array(n);
          for (const idx of grp.pixels) subSeed[idx] = 255;
          // Dilater
          const subDil = dilate(subSeed, w, h, CHILD_DILATE_K, CHILD_DILATE_K);
          for (let i = 0; i < n; i++) if (!cMask[i]) subDil[i] = 0;
          // CC
          const subCC = connectedComponents(subDil, w, h);
          for (let l = 1; l < subCC.numLabels; l++) {
            const st = subCC.stats[l];
            if (st.area < CHILD_MIN_SEED_AREA) continue;
            let sH = 0, cnt = 0;
            for (let y = st.top; y < st.top + st.height && y < h; y++)
              for (let x = st.left; x < st.left + st.width && x < w; x++) {
                const i = y * w + x;
                if (subCC.labels[i] === l && subSeed[i]) { sH += hH[i]; cnt++; }
              }
            clusters.push({
              label: l, _subCC: subCC, _subSeed: subSeed,
              bbox: [st.left, st.top, st.width, st.height],
              area: st.area, meanHue: cnt > 0 ? sH / cnt : 0
            });
          }
        }

        // Fusion des clusters de teinte similaire + chevauchement Y ou proximité X
        // En P6a, les clusters sont déjà séparés par teinte. Des fragments de
        // même teinte qui se chevauchent en Y sont forcément le même enfant
        // (ex: bordure gauche et droite d'un diamant). Pas de limite X-gap.
        const cpar = Array.from({ length: clusters.length }, (_, i) => i);
        function cfind(x) { while (cpar[x] !== x) { cpar[x] = cpar[cpar[x]]; x = cpar[x]; } return x; }
        function cunion(a, b) { a = cfind(a); b = cfind(b); if (a !== b) cpar[Math.max(a, b)] = Math.min(a, b); }

        for (let i = 0; i < clusters.length; i++) {
          for (let j = i + 1; j < clusters.length; j++) {
            let hd = Math.abs(clusters[i].meanHue - clusters[j].meanHue);
            hd = Math.min(hd, 180 - hd);
            if (hd > CHILD_HUE_GROUP) continue;
            const [, ay, , ah] = clusters[i].bbox;
            const [, by, , bh] = clusters[j].bbox;
            const yOverlap = !(ay + ah < by || by + bh < ay);
            if (yOverlap) cunion(i, j);
          }
        }

        const cgroups = {};
        for (let i = 0; i < clusters.length; i++) {
          const root = cfind(i);
          if (!cgroups[root]) cgroups[root] = [];
          cgroups[root].push(i);
        }

        // Pour chaque groupe, extraire le masque enfant
        for (const indices of Object.values(cgroups)) {
          let mnx = w, mny = h, mxx = 0, mxy = 0;
          for (const idx of indices) {
            const [bx, by, bw, bh] = clusters[idx].bbox;
            mnx = Math.min(mnx, bx); mny = Math.min(mny, by);
            mxx = Math.max(mxx, bx + bw); mxy = Math.max(mxy, by + bh);
          }

          // Teinte dominante du cluster (pour la détection de forme)
          let clusterHueSum = 0, clusterHueCnt = 0;
          for (const idx of indices) {
            const c = clusters[idx];
            const [cbx, cby, cbw, cbh] = c.bbox;
            for (let y = cby; y < cby + cbh && y < h; y++)
              for (let x = cbx; x < cbx + cbw && x < w; x++) {
                const i = y * w + x;
                if (c._subSeed && c._subSeed[i]) {
                  clusterHueSum += hH[i]; clusterHueCnt++;
                }
              }
          }
          const clusterMeanHue = clusterHueCnt > 0 ? clusterHueSum / clusterHueCnt : 0;

          // ── Tentative de détection de forme losange (diamant Scratch) ──
          // Cherche dans l'IMAGE BRUTE les pixels de la teinte dominante
          // du cluster dans une zone élargie autour de la bbox.
          // Si la forme est un losange → masque polygone géométrique.
          const DIAMOND_EXPAND = 10;
          const DIAMOND_HUE_RANGE = 20;
          const DIAMOND_MIN_SAT = 40;
          const DIAMOND_MARGIN = 1;

          let isDiamond = false;
          const childFilled = new Uint8Array(n);

          {
            const dsx = Math.max(0, mnx - DIAMOND_EXPAND);
            const dsy = Math.max(0, mny - DIAMOND_EXPAND);
            const dex = Math.min(w, mxx + DIAMOND_EXPAND);
            const dey = Math.min(h, mxy + DIAMOND_EXPAND);

            // Per-row extent des pixels de teinte cluster dans l'image brute
            let drowsRaw = [];
            for (let y = dsy; y < dey; y++) {
              let left = -1, right = -1;
              for (let x = dsx; x < dex; x++) {
                const i = y * w + x;
                if (hS[i] < DIAMOND_MIN_SAT) continue;
                let hd = Math.abs(hH[i] - clusterMeanHue);
                hd = Math.min(hd, 180 - hd);
                if (hd <= DIAMOND_HUE_RANGE) {
                  if (left < 0) left = x;
                  right = x;
                }
              }
              if (left >= 0) drowsRaw.push({ y, left, right, w: right - left + 1 });
            }

            // Garder le plus long segment contigu (pas de gap Y > 1)
            if (drowsRaw.length > 1) {
              let bestStart = 0, bestLen = 1, curStart = 0, curLen = 1;
              for (let k = 1; k < drowsRaw.length; k++) {
                if (drowsRaw[k].y - drowsRaw[k - 1].y <= 1) {
                  curLen++;
                } else { curStart = k; curLen = 1; }
                if (curLen > bestLen) { bestLen = curLen; bestStart = curStart; }
              }
              drowsRaw = drowsRaw.slice(bestStart, bestStart + bestLen);
            }

            // Lissage médian des bords gauche/droit (fenêtre 5)
            // pour absorber les éléments internes (signes, reporters)
            const drows = drowsRaw.map((r, k, arr) => {
              const half = 2;
              const lo = Math.max(0, k - half), hi = Math.min(arr.length - 1, k + half);
              const lefts = [], rights = [];
              for (let j = lo; j <= hi; j++) { lefts.push(arr[j].left); rights.push(arr[j].right); }
              lefts.sort((a, b) => a - b); rights.sort((a, b) => a - b);
              const ml = lefts[lefts.length >> 1];
              const mr = rights[rights.length >> 1];
              return { y: r.y, left: ml, right: mr, w: mr - ml + 1 };
            });

            // Validation losange : pente ≤5, ratio largeur/hauteur ≥2.5,
            // profil monotone (tolérance ±1)
            if (drows.length >= 8) {
              let maxDelta = 0;
              for (let i = 1; i < drows.length; i++) {
                maxDelta = Math.max(maxDelta,
                  Math.abs(drows[i].left - drows[i - 1].left),
                  Math.abs(drows[i].right - drows[i - 1].right));
              }
              let maxW = 0;
              for (const r of drows) if (r.w > maxW) maxW = r.w;
              const totalH = drows[drows.length - 1].y - drows[0].y + 1;
              const ratio = maxW / totalH;

              // Forme diamant : le bord GAUCHE doit former un V (pointe
              // vers la gauche au centre vertical). Le bord droit peut être
              // irrégulier (bulles blanches masquant la bordure verte) donc
              // on se base principalement sur le bord gauche.
              const leftRow = drows.reduce((b, r) => r.left < b.left ? r : b);
              const rightRow = drows.reduce((b, r) => r.right > b.right ? r : b);
              const topY = drows[0].y, botY = drows[drows.length - 1].y;
              const rangeY = botY - topY;
              const marginY = rangeY * 0.15;
              // La pointe gauche est au centre vertical
              const leftTipInCenter = leftRow.y > topY + marginY && leftRow.y < botY - marginY;
              // Le bord gauche se rétrécit aux extrémités (V shape)
              const leftNarrows = drows[0].left > leftRow.left + 1
                               && drows[drows.length - 1].left > leftRow.left + 1;
              const isDiamondShape = leftTipInCenter && leftNarrows;

              if (maxDelta <= 5 && ratio >= 2.5 && isDiamondShape) {
                isDiamond = true;

                // Les drows initiales sont clippées par DIAMOND_EXPAND →
                // le bord droit est souvent tronqué. On re-scanne l'image
                // SANS limite X pour trouver l'étendue réelle du diamant.
                const fullDrows = [];
                const scanYS = Math.max(0, drows[0].y - 2);
                const scanYE = Math.min(h - 1, drows[drows.length - 1].y + 2);
                for (let fy = scanYS; fy <= scanYE; fy++) {
                  let fl = -1, fr = -1;
                  for (let fx = Math.max(0, leftRow.left - 5); fx < w; fx++) {
                    const fi = fy * w + fx;
                    if (hS[fi] < DIAMOND_MIN_SAT) continue;
                    let fhd = Math.abs(hH[fi] - clusterMeanHue);
                    fhd = Math.min(fhd, 180 - fhd);
                    if (fhd <= DIAMOND_HUE_RANGE) {
                      if (fl < 0) fl = fx;
                      fr = fx;
                    }
                  }
                  if (fl >= 0) fullDrows.push({ y: fy, left: fl, right: fr });
                }

                // Lissage médian des bords (fenêtre 5)
                const fSm = fullDrows.map((r, k, arr) => {
                  const hl = 2;
                  const lo = Math.max(0, k - hl), hi = Math.min(arr.length - 1, k + hl);
                  const ls = [], rs = [];
                  for (let j = lo; j <= hi; j++) { ls.push(arr[j].left); rs.push(arr[j].right); }
                  ls.sort((a, b) => a - b); rs.sort((a, b) => a - b);
                  return { y: r.y, left: ls[ls.length >> 1], right: rs[rs.length >> 1] };
                });

                // 6 sommets depuis les drows complètes
                const fTop = fSm[0];
                const fBot = fSm[fSm.length - 1];
                const fLeft = fSm.reduce((b, r) => r.left < b.left ? r : b);
                const fRight = fSm.reduce((b, r) => r.right > b.right ? r : b);

                // Sécurité : si fRight n'est pas au centre vertical
                // (bulles masquant le bord), extrapoler par symétrie gauche
                const fLD = fTop.left - fLeft.left;
                const rpX = Math.max(fRight.right, fTop.right + fLD);
                const rpY = fLeft.y;

                const M = DIAMOND_MARGIN;
                const poly = [
                  [fTop.left - M,    fTop.y - M],
                  [fTop.right + M,   fTop.y - M],
                  [rpX + M,          rpY],
                  [fBot.right + M,   fBot.y + M],
                  [fBot.left - M,    fBot.y + M],
                  [fLeft.left - M,   fLeft.y]
                ];

                // Scanline fill du polygone
                let polyMinY = h, polyMaxY = 0;
                for (const v of poly) { if (v[1] < polyMinY) polyMinY = v[1]; if (v[1] > polyMaxY) polyMaxY = v[1]; }
                for (let y = Math.max(0, Math.floor(polyMinY)); y <= Math.min(h - 1, Math.ceil(polyMaxY)); y++) {
                  const xs = [];
                  for (let i = 0; i < poly.length; i++) {
                    const j = (i + 1) % poly.length;
                    const y0 = poly[i][1], y1 = poly[j][1];
                    if ((y0 <= y && y1 > y) || (y1 <= y && y0 > y)) {
                      const t = (y - y0) / (y1 - y0);
                      xs.push(poly[i][0] + t * (poly[j][0] - poly[i][0]));
                    }
                  }
                  xs.sort((a, b) => a - b);
                  for (let k = 0; k < xs.length - 1; k += 2) {
                    const x0 = Math.max(0, Math.ceil(xs[k]));
                    const x1 = Math.min(w - 1, Math.floor(xs[k + 1]));
                    for (let x = x0; x <= x1; x++) {
                      childFilled[y * w + x] = 255;
                    }
                  }
                }

                // ── Capturer les éléments internes au diamant ──
                // Le polygone couvre la forme géométrique du diamant (bordure
                // verte). Mais les dropdowns/pills internes (ex: "Equipped
                // Penguin Cursor") sont de la MÊME couleur que le conteneur
                // (orange). On inclut tous les pixels du masque conteneur qui
                // sont entre les bords gauche/droit du polygone sur chaque ligne.
                for (let y = Math.max(0, Math.floor(polyMinY)); y <= Math.min(h - 1, Math.ceil(polyMaxY)); y++) {
                  let leftF = -1, rightF = -1;
                  for (let x = 0; x < w; x++) {
                    if (childFilled[y * w + x]) { if (leftF < 0) leftF = x; rightF = x; }
                  }
                  if (leftF >= 0 && rightF > leftF) {
                    for (let x = leftF; x <= rightF; x++) {
                      const idx = y * w + x;
                      if (cMask[idx] && !childFilled[idx]) childFilled[idx] = 255;
                    }
                  }
                }
              }
            }
          }

          // ── Fallback : seed-row + smart-trim pour les formes non-losange ──
          if (!isDiamond) {
            const expand = 2;
            const emnx = Math.max(0, mnx - expand), emny = Math.max(0, mny - expand);
            const emxx = Math.min(w, mxx + expand), emxy = Math.min(h, mxy + expand);

            const rawChild = new Uint8Array(n);
            for (let y = emny; y < emxy; y++)
              for (let x = emnx; x < emxx; x++) {
                const i = y * w + x;
                if (cMask[i]) rawChild[i] = 255;
              }
            const filled = fillExternal(rawChild, w, h);

            const CLEANUP_HUE_THRESH = 25;
            const CLEANUP_SEED_SAT = 50;

            const seedMask = new Uint8Array(n);
            for (let i = 0; i < n; i++) {
              if (!filled[i] || hS[i] < CLEANUP_SEED_SAT) continue;
              let hd = Math.abs(hH[i] - cHue); hd = Math.min(hd, 180 - hd);
              if (hd > CLEANUP_HUE_THRESH) seedMask[i] = 255;
            }

            for (let y = 0; y < h; y++) {
              let leftSeed = -1, rightSeed = -1;
              for (let x = 0; x < w; x++) { if (seedMask[y * w + x]) { leftSeed = x; break; } }
              for (let x = w - 1; x >= 0; x--) { if (seedMask[y * w + x]) { rightSeed = x; break; } }
              if (leftSeed < 0 || rightSeed < 0 || rightSeed <= leftSeed) continue;
              for (let x = leftSeed; x <= rightSeed; x++) {
                if (filled[y * w + x]) childFilled[y * w + x] = 255;
              }
            }

            // Smart trim : pixels conteneur-hue touchant l'extérieur
            for (let pass = 0; pass < 3; pass++) {
              let removed = 0;
              for (let i = 0; i < n; i++) {
                if (!childFilled[i] || hS[i] < 30) continue;
                let hd = Math.abs(hH[i] - cHue); hd = Math.min(hd, 180 - hd);
                if (hd > CLEANUP_HUE_THRESH) continue;
                const x = i % w, y = (i - x) / w;
                for (const [dx, dy] of [[-1, 0], [1, 0], [0, -1], [0, 1]]) {
                  const nx = x + dx, ny = y + dy;
                  if (nx < 0 || nx >= w || ny < 0 || ny >= h || !filled[ny * w + nx]) {
                    childFilled[i] = 0; removed++; break;
                  }
                }
              }
              if (removed === 0) break;
            }
          }

          // Bbox et stats de l'enfant
          let cx1 = w, cy1 = h, cx2 = -1, cy2 = -1, childArea = 0;
          for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
            if (!childFilled[y * w + x]) continue;
            childArea++;
            if (x < cx1) cx1 = x; if (y < cy1) cy1 = y;
            if (x > cx2) cx2 = x; if (y > cy2) cy2 = y;
          }
          if (cx2 < 0 || childArea < CHILD_MIN_SEED_AREA) continue;

          // Teinte dominante de l'enfant (déjà calculée plus haut)
          const chH = clusterHueSum;
          const chCnt = clusterHueCnt;
          // Couleur moyenne de l'enfant
          let crr = 0, cgg = 0, cbb = 0, ccnt = 0;
          for (let i = 0; i < n; i++) {
            if (!childFilled[i]) continue;
            const o = i * 4;
            crr += rgba[o]; cgg += rgba[o + 1]; cbb += rgba[o + 2]; ccnt++;
          }

          childrenToAdd.push({
            parentIdx: ci,
            mask: childFilled,
            bbox: [cx1, cy1, cx2 - cx1 + 1, cy2 - cy1 + 1],
            area: childArea,
            meanHue: chCnt > 0 ? chH / chCnt : 0,
            meanColor: ccnt > 0 ? [crr / ccnt, cgg / ccnt, cbb / ccnt] : [128, 128, 128],
            isDiamond
          });

          // Soustraire l'enfant du conteneur
          for (let i = 0; i < n; i++) {
            if (childFilled[i]) cMask[i] = 0;
          }

          // Pour les losanges : nettoyer les résidus d'antialiasing dans le conteneur
          // Les pixels de teinte du losange (vert) dans la zone élargie du losange
          // sont forcément des résidus de bordure → les retirer du conteneur
          if (isDiamond) {
            const cleanExpand = 5;
            const clx = Math.max(0, cx1 - cleanExpand), cly = Math.max(0, cy1 - cleanExpand);
            const crx = Math.min(w, cx2 + 1 + cleanExpand), cry = Math.min(h, cy2 + 1 + cleanExpand);
            for (let y = cly; y < cry; y++) {
              for (let x = clx; x < crx; x++) {
                const i = y * w + x;
                if (!cMask[i]) continue;
                if (hS[i] < CHILD_MIN_SAT) continue;
                let hd = Math.abs(hH[i] - clusterMeanHue);
                hd = Math.min(hd, 180 - hd);
                if (hd <= CHILD_HUE_DIFF) cMask[i] = 0;
              }
            }
          }
        }
      }

      // Ajouter les enfants comme nouveaux blocs
      for (const child of childrenToAdd) {
        finalBlocks.push({
          bbox: child.bbox,
          area: child.area,
          meanColor: child.meanColor,
          meanHue: child.meanHue,
          isP6aChild: true,
          isDiamond: !!child.isDiamond
        });
        finalMasks.push(child.mask);
      }
      phases.p6a_children_extracted = childrenToAdd.length;
    }

    // ── P6a1b : Fusion horizontale des fragments P6a sur la même ligne ──
    // Quand un bloc (ex: bleu) contient des paramètres de couleurs différentes
    // (vert, violet), P6a les extrait séparément. On les re-fusionne ici
    // s'ils sont sur la même bande Y avec un overlap Y > 50%.
    {
      const nFB = finalBlocks.length;
      const fbpar = Array.from({ length: nFB }, (_, i) => i);
      function fbfind(x) { while (fbpar[x] !== x) { fbpar[x] = fbpar[fbpar[x]]; x = fbpar[x]; } return x; }
      function fbunion(a, b) { a = fbfind(a); b = fbfind(b); if (a !== b) fbpar[Math.max(a, b)] = Math.min(a, b); }

      // Identifier les conteneurs
      const containerBboxes = [];
      for (let i = 0; i < nFB; i++) {
        if (finalBlocks[i].bbox[3] > CONTAINER_HEIGHT_THRESHOLD) containerBboxes.push(finalBlocks[i].bbox);
      }
      // Fonction : bloc est dans un conteneur ?
      function isInsideContainer(bbox) {
        const [bx, by, bw, bh] = bbox;
        const bcx = bx + bw / 2, bcy = by + bh / 2;
        for (const [cx, cy, cw, ch] of containerBboxes) {
          if (bcx > cx && bcx < cx + cw && bcy > cy && bcy < cy + ch) return true;
        }
        return false;
      }

      for (let i = 0; i < nFB; i++) {
        if (finalBlocks[i].bbox[3] > CONTAINER_HEIGHT_THRESHOLD) continue;
        if (finalBlocks[i].isDiamond) continue;
        if (!isInsideContainer(finalBlocks[i].bbox)) continue;
        for (let j = i + 1; j < nFB; j++) {
          if (finalBlocks[j].bbox[3] > CONTAINER_HEIGHT_THRESHOLD) continue;
          if (finalBlocks[j].isDiamond) continue;
          if (!isInsideContainer(finalBlocks[j].bbox)) continue;
          if (fbfind(i) === fbfind(j)) continue;
          const [, ay, , ah] = finalBlocks[i].bbox;
          const [, by, , bh] = finalBlocks[j].bbox;
          const overlapY = Math.max(0, Math.min(ay + ah, by + bh) - Math.max(ay, by));
          const minH = Math.min(ah, bh);
          if (overlapY > minH * 0.5) {
            // Vérifier proximité X (gap < 30px)
            const [ax] = finalBlocks[i].bbox;
            const [bx] = finalBlocks[j].bbox;
            const aw = finalBlocks[i].bbox[2], bw = finalBlocks[j].bbox[2];
            const xGap = Math.max(0, Math.max(ax, bx) - Math.min(ax + aw, bx + bw));
            if (xGap < 30) fbunion(i, j);
          }
        }
      }

      const fbgroups = {};
      for (let i = 0; i < nFB; i++) {
        const root = fbfind(i);
        if (!fbgroups[root]) fbgroups[root] = [];
        fbgroups[root].push(i);
      }

      // Fusionner les groupes avec > 1 membre
      let merged = false;
      for (const indices of Object.values(fbgroups)) {
        if (indices.length <= 1) continue;
        merged = true;
        // Fusionner tous dans le premier
        const target = indices[0];
        const tm = finalMasks[target];
        for (let k = 1; k < indices.length; k++) {
          const src = indices[k];
          const sm = finalMasks[src];
          for (let p = 0; p < n; p++) if (sm[p]) tm[p] = 255;
        }
        // Recalculer bbox
        let mnx = w, mny = h, mxx = -1, mxy = -1, area = 0;
        for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
          if (!tm[y * w + x]) continue;
          area++; if (x < mnx) mnx = x; if (y < mny) mny = y;
          if (x > mxx) mxx = x; if (y > mxy) mxy = y;
        }
        finalBlocks[target].bbox = [mnx, mny, mxx - mnx + 1, mxy - mny + 1];
        finalBlocks[target].area = area;
        // Marquer les sources comme supprimées
        for (let k = 1; k < indices.length; k++) finalBlocks[indices[k]]._deleted = true;
      }
      if (merged) {
        const nb = [], nm = [];
        for (let i = 0; i < finalBlocks.length; i++) {
          if (!finalBlocks[i]._deleted) { nb.push(finalBlocks[i]); nm.push(finalMasks[i]); }
        }
        finalBlocks.length = 0; finalMasks.length = 0;
        finalBlocks.push(...nb); finalMasks.push(...nm);
      }
    }

    // ── P6a2 : Découpe des blocs empilés de même couleur ─────────────
    // Deux blocs Scratch de même teinte empilés → fusionnés en P3.
    // Le connecteur crée un retrait de 1-2px du bord gauche sur 3-6 lignes.
    // Détection par profil de position du bord gauche.
    {
      const STACK_MAX_H = 50;
      const newBlocks = [];
      const newMasks = [];

      for (let bi = 0; bi < finalBlocks.length; bi++) {
        const [bx0, by0, bw0, bh0] = finalBlocks[bi].bbox;
        if (bh0 <= STACK_MAX_H || bh0 > CONTAINER_HEIGHT_THRESHOLD) {
          newBlocks.push(finalBlocks[bi]);
          newMasks.push(finalMasks[bi]);
          continue;
        }

        const fm = finalMasks[bi];

        // Profil du bord gauche : position X la plus à gauche par ligne
        const leftEdge = [];
        for (let y = by0; y < by0 + bh0 && y < h; y++) {
          let lx = -1;
          for (let x = bx0; x < bx0 + bw0 && x < w; x++) {
            if (fm[y * w + x]) { lx = x; break; }
          }
          leftEdge.push({ y, lx });
        }

        // Le "mode" du bord gauche (position la plus fréquente)
        const lxCounts = {};
        for (const e of leftEdge) {
          if (e.lx >= 0) lxCounts[e.lx] = (lxCounts[e.lx] || 0) + 1;
        }
        let modeLx = -1, modeCount = 0;
        for (const [lx, cnt] of Object.entries(lxCounts)) {
          if (cnt > modeCount) { modeCount = cnt; modeLx = +lx; }
        }

        // Chercher des bandes de lignes en retrait (lx > modeLx)
        // qui ne sont PAS tout en haut ou tout en bas (zone d'arrondi)
        const margin = Math.max(3, Math.floor(leftEdge.length * 0.1));
        const splits = [];
        let bandStart = -1;
        for (let i = margin; i < leftEdge.length - margin; i++) {
          const indent = leftEdge[i].lx >= 0 && leftEdge[i].lx > modeLx;
          if (indent) {
            if (bandStart < 0) bandStart = i;
          } else {
            if (bandStart >= 0) {
              const bandLen = i - bandStart;
              if (bandLen >= 2 && bandLen <= 8) {
                const splitY = leftEdge[bandStart + Math.floor(bandLen / 2)].y;
                splits.push(splitY);
              }
              bandStart = -1;
            }
          }
        }

        if (splits.length === 0) {
          newBlocks.push(finalBlocks[bi]);
          newMasks.push(finalMasks[bi]);
          continue;
        }

        // Découper le masque aux points de split
        const cutYs = [by0, ...splits, by0 + bh0];
        for (let s = 0; s < cutYs.length - 1; s++) {
          const yA = cutYs[s], yB = cutYs[s + 1];
          const subMask = new Uint8Array(n);
          let mnx = w, mny = h, mxx = -1, mxy = -1, area = 0;
          for (let y = yA; y < yB && y < h; y++) for (let x = bx0; x < bx0 + bw0 && x < w; x++) {
            const j = y * w + x;
            if (fm[j]) {
              subMask[j] = 255; area++;
              if (x < mnx) mnx = x; if (y < mny) mny = y;
              if (x > mxx) mxx = x; if (y > mxy) mxy = y;
            }
          }
          if (mxx < 0 || area < SCRATCH_MIN_AREA) continue;
          newBlocks.push({
            bbox: [mnx, mny, mxx - mnx + 1, mxy - mny + 1],
            area, meanColor: finalBlocks[bi].meanColor,
            meanHue: finalBlocks[bi].meanHue,
            isP6aChild: finalBlocks[bi].isP6aChild,
            isDiamond: finalBlocks[bi].isDiamond
          });
          newMasks.push(subMask);
        }
      }

      finalBlocks.length = 0; finalMasks.length = 0;
      finalBlocks.push(...newBlocks); finalMasks.push(...newMasks);
    }

    // ── P6a3 : Extraction des blocs internes de même couleur dans les conteneurs ──
    // Les blocs Scratch (orange) à l'intérieur d'un conteneur de même couleur
    // sont fusionnés en P3. On détecte les blocs internes par :
    //   1. Identifier la barre latérale gauche du conteneur (zones "narrow")
    //   2. Les zones entre barres narrow contiennent des blocs empilés
    //   3. Subdiviser ces zones par les connecteurs (retraits du bord gauche)
    //   4. Le header et la barre de fermeture restent dans le conteneur
    //   5. Les zones déjà couvertes par un enfant P6a existant sont ignorées
    {
      const childrenToAdd = [];

      for (let ci = 0; ci < finalBlocks.length; ci++) {
        const [bx0, by0, bw0, bh0] = finalBlocks[ci].bbox;
        if (bh0 <= CONTAINER_HEIGHT_THRESHOLD) continue;
        const cMask = finalMasks[ci];

        // Profil par ligne
        const rowLeft = new Int32Array(h).fill(-1);
        const rowRight = new Int32Array(h).fill(-1);
        const rowWidth = new Int32Array(h);
        for (let y = by0; y < by0 + bh0 && y < h; y++) {
          let cnt = 0;
          for (let x = bx0; x < bx0 + bw0 && x < w; x++) {
            if (cMask[y * w + x]) {
              if (rowLeft[y] < 0) rowLeft[y] = x;
              rowRight[y] = x; cnt++;
            }
          }
          rowWidth[y] = cnt;
        }

        // Trouver la barre latérale droite typique pour les zones étroites
        // C'est le mode du bord droit pour les lignes à faible largeur
        const maxW = Math.max(...rowWidth);
        if (maxW < 60) continue;
        const narrowThresh = maxW * 0.25;
        // Calculer barRight : le bord droit typique de la barre latérale seule.
        // On prend le MODE (valeur la plus fréquente) des bords droits des rangées
        // les plus étroites (< narrowThresh/2), qui sont les vraies barres.
        // Si pas assez de rangées très étroites, on utilise toutes les narrow.
        const rightBuckets = {};
        let veryNarrowCount = 0;
        for (let y = by0; y < by0 + bh0 && y < h; y++) {
          if (rowWidth[y] <= 0 || rowRight[y] < 0) continue;
          if (rowWidth[y] < narrowThresh * 0.6) {
            const bucket = Math.round(rowRight[y] / 3) * 3;
            rightBuckets[bucket] = (rightBuckets[bucket] || 0) + 1;
            veryNarrowCount++;
          }
        }
        if (veryNarrowCount < 8) continue;
        let barRight = -1, bestBucketCnt = 0;
        for (const [bucket, cnt] of Object.entries(rightBuckets)) {
          if (cnt > bestBucketCnt) { bestBucketCnt = cnt; barRight = +bucket + 3; }
        }
        if (barRight < 0) continue;

        // Mode du bord gauche (pour détecter les connecteurs)
        const leftCounts = {};
        for (let y = by0; y < by0 + bh0 && y < h; y++) {
          if (rowLeft[y] >= 0) leftCounts[rowLeft[y]] = (leftCounts[rowLeft[y]] || 0) + 1;
        }
        let modeLx = bx0, modeCount = 0;
        for (const [lx, cnt] of Object.entries(leftCounts)) {
          if (cnt > modeCount) { modeCount = cnt; modeLx = +lx; }
        }

        // Parcourir toutes les lignes et créer des "segments" séparés par :
        // - Les narrow bars (right <= barRight + 5)
        // - Les connecteurs (retrait du bord gauche de >= 2px pendant 2-8 lignes)
        // Un segment est un groupe continu de lignes "larges" (right > barRight + 15)
        const segments = [];
        let segStart = -1;
        for (let y = by0; y < by0 + bh0 && y < h; y++) {
          const isWide = rowRight[y] > barRight + 15 && rowWidth[y] > narrowThresh;
          if (isWide) {
            if (segStart < 0) segStart = y;
          } else {
            if (segStart >= 0 && y - segStart >= 5) {
              segments.push([segStart, y]);
            }
            segStart = -1;
          }
        }
        if (segStart >= 0 && (by0 + bh0 - segStart) >= 5) {
          segments.push([segStart, by0 + bh0]);
        }

        // Pour chaque segment large, appliquer le split par connecteurs
        // (retraits du bord gauche, comme P6a2) ET par changements drastiques
        // du bord droit (header étroit → bloc large).
        const allSubBlocks = [];
        for (const [sStart, sEnd] of segments) {
          const segLen = sEnd - sStart;
          if (segLen < 10) { allSubBlocks.push([sStart, sEnd]); continue; }

          // Profil du bord gauche et droit dans ce segment
          const segLeftEdge = [];
          const segRightEdge = [];
          for (let y = sStart; y < sEnd && y < h; y++) {
            segLeftEdge.push({ y, lx: rowLeft[y] });
            segRightEdge.push({ y, rx: rowRight[y] });
          }

          const splits = [sStart];

          // Split par retrait du bord gauche (connecteurs)
          const margin = Math.max(2, Math.floor(segLen * 0.08));
          let bandStart = -1;
          for (let i = margin; i < segLeftEdge.length - margin; i++) {
            const indent = segLeftEdge[i].lx >= 0 && segLeftEdge[i].lx > modeLx;
            if (indent) {
              if (bandStart < 0) bandStart = i;
            } else {
              if (bandStart >= 0) {
                const bandLen = i - bandStart;
                if (bandLen >= 2 && bandLen <= 8) {
                  const splitY = segLeftEdge[bandStart + Math.floor(bandLen / 2)].y;
                  splits.push(splitY);
                }
                bandStart = -1;
              }
            }
          }

          // Split par saut drastique du bord droit
          // (header R=~116 → bloc R=~343 = jump > 100px)
          for (let i = 3; i < segRightEdge.length - 3; i++) {
            const prevR = segRightEdge[i - 1].rx;
            const currR = segRightEdge[i].rx;
            if (prevR > 0 && currR > 0 && currR - prevR > 80) {
              const splitY = segRightEdge[i].y;
              // Éviter les doublons avec les splits déjà trouvés
              const dup = splits.some(s => Math.abs(s - splitY) < 5);
              if (!dup) splits.push(splitY);
            }
          }

          splits.push(sEnd);
          splits.sort((a, b) => a - b);

          for (let s = 0; s < splits.length - 1; s++) {
            const yA = splits[s], yB = splits[s + 1];
            if (yB - yA < 10) continue;
            allSubBlocks.push([yA, yB]);
          }
        }

        if (allSubBlocks.length === 0) continue;

        // Déterminer si le premier sub-block est le header du conteneur
        // Le header commence au sommet du conteneur et est en position avant
        // la zone des blocs internes. On le skip seulement s'il commence
        // dans les 10 premières lignes du conteneur.
        const skipFirst = allSubBlocks[0][0] <= by0 + 10;

        for (let si = (skipFirst ? 1 : 0); si < allSubBlocks.length; si++) {
          const [yStart, yEnd] = allSubBlocks[si];
          const subH = yEnd - yStart;

          // Ignorer les sous-blocs trop petits (barre de fermeture, transitions)
          if (subH < 22) continue;

          // Vérifier que cette zone n'est pas déjà majoritairement couverte
          // par un enfant LARGE (pas un petit paramètre) de même teinte
          let alreadyCovered = false;
          for (let oi = 0; oi < finalBlocks.length; oi++) {
            if (oi === ci) continue;
            const [ox, oy, ow, oh] = finalBlocks[oi].bbox;
            const overlapY = Math.max(0, Math.min(oy + oh, yEnd) - Math.max(oy, yStart));
            // La largeur de l'enfant doit couvrir ≥60% de la zone pour bloquer
            if (overlapY > subH * 0.5 && ow > bw0 * 0.6) {
              alreadyCovered = true; break;
            }
          }
          if (alreadyCovered) continue;

          // Extraire les pixels du conteneur dans cette zone
          // La barre latérale du conteneur doit rester dans le conteneur.
          // L'indentation Scratch entre la barre et le slot est ~16px.
          // cutX = bord gauche du conteneur + indentation Scratch
          const SCRATCH_SLOT_INDENT = 16;
          const cutX = modeLx + SCRATCH_SLOT_INDENT;

          const childMask = new Uint8Array(n);
          let cx1 = w, cy1 = h, cx2 = -1, cy2 = -1, cArea = 0;
          for (let y = yStart; y < yEnd && y < h; y++) {
            for (let x = bx0; x < bx0 + bw0 && x < w; x++) {
              const j = y * w + x;
              if (!cMask[j]) continue;
              if (x < cutX) continue; // barre latérale → garder dans le conteneur
              childMask[j] = 255;
              cArea++;
              if (x < cx1) cx1 = x; if (y < cy1) cy1 = y;
              if (x > cx2) cx2 = x; if (y > cy2) cy2 = y;
            }
          }
          if (cx2 < 0 || cArea < 100) continue;
          const cW = cx2 - cx1 + 1, cH = cy2 - cy1 + 1;
          if (cH < 20 || cW < 40) continue;

          // Couleur moyenne
          let cr = 0, cg = 0, cb = 0, ccnt = 0, chH = 0, chCnt = 0;
          for (let j = 0; j < n; j++) {
            if (!childMask[j]) continue;
            cr += rgba[j * 4]; cg += rgba[j * 4 + 1]; cb += rgba[j * 4 + 2]; ccnt++;
            if (hS[j] > 40) { chH += hH[j]; chCnt++; }
          }

          childrenToAdd.push({
            parentIdx: ci,
            mask: childMask,
            bbox: [cx1, cy1, cW, cH],
            area: cArea,
            meanColor: ccnt > 0 ? [cr / ccnt, cg / ccnt, cb / ccnt] : [128, 128, 128],
            meanHue: chCnt > 0 ? chH / chCnt : finalBlocks[ci].meanHue
          });

          // Soustraire du conteneur (childMask exclut déjà la barre)
          for (let j = 0; j < n; j++) if (childMask[j]) cMask[j] = 0;
        }
      }

      for (const child of childrenToAdd) {
        finalBlocks.push({
          bbox: child.bbox,
          area: child.area,
          meanColor: child.meanColor,
          meanHue: child.meanHue,
          isP6aChild: true
        });
        finalMasks.push(child.mask);
      }

      // Absorber les blocs existants qui sont positionnellement contenus
      // dans un bloc P6a3 (ex: paramètre vert à l'intérieur d'un bloc orange).
      // Ces blocs sont des doublons — leur visuel est déjà dans le bloc P6a3.
      if (childrenToAdd.length > 0) {
        const p6a3Start = finalBlocks.length - childrenToAdd.length;
        const toRemove = new Set();
        for (let oi = 0; oi < p6a3Start; oi++) {
          if (finalBlocks[oi].bbox[3] > CONTAINER_HEIGHT_THRESHOLD) continue; // skip conteneurs
          const [ox, oy, ow, oh] = finalBlocks[oi].bbox;
          const ocx = ox + ow / 2, ocy = oy + oh / 2;
          for (let ni = p6a3Start; ni < finalBlocks.length; ni++) {
            const [nx, ny, nw, nh] = finalBlocks[ni].bbox;
            const margin = 3;
            // Le centre du bloc existant est dans la bbox du nouveau bloc P6a3
            if (ocx >= nx - margin && ocx <= nx + nw + margin &&
                ocy >= ny - margin && ocy <= ny + nh + margin) {
              // Fusionner les pixels dans le bloc P6a3
              const nm = finalMasks[ni], om = finalMasks[oi];
              for (let k = 0; k < n; k++) if (om[k]) nm[k] = 255;
              toRemove.add(oi);
              break;
            }
          }
        }
        if (toRemove.size > 0) {
          const newBlocks2 = [], newMasks2 = [];
          for (let i = 0; i < finalBlocks.length; i++) {
            if (!toRemove.has(i)) { newBlocks2.push(finalBlocks[i]); newMasks2.push(finalMasks[i]); }
          }
          finalBlocks.length = 0; finalMasks.length = 0;
          finalBlocks.push(...newBlocks2); finalMasks.push(...newMasks2);
        }
      }
    }

    // ── P6b : Remplissage des trous internes (bulles blanches d'entrée) ──
    // Ajouter les pixels colorés non-assignés dans la bbox (antialiasing
    // entre bulle et corps du bloc), puis fillExternal ferme les bulles.
    // Les résidus de blocs adjacents seront nettoyés par P6c après.

    // D'abord, calculer la teinte pure de chaque bloc AVANT expansion
    const blockBodyHues = [];
    for (let i = 0; i < finalBlocks.length; i++) {
      const fm = finalMasks[i];
      let pureHSum = 0, pureHCnt = 0;
      for (let j = 0; j < n; j++) {
        if (!fm[j] || hS[j] < 150) continue;
        pureHSum += hH[j]; pureHCnt++;
      }
      blockBodyHues.push(pureHCnt > 0 ? pureHSum / pureHCnt : finalBlocks[i].meanHue);
    }

    {
      const allBlocksMask = new Uint8Array(n);
      for (let i = 0; i < finalMasks.length; i++) {
        for (let j = 0; j < n; j++) if (finalMasks[i][j]) allBlocksMask[j] = 255;
      }

      for (let i = 0; i < finalBlocks.length; i++) {
        const [, , , bh] = finalBlocks[i].bbox;
        if (bh > CONTAINER_HEIGHT_THRESHOLD) continue;
        // Les diamants ont un masque polygone parfait issu de P6a,
        // P6b (row-fill + fillExternal) détruirait leurs pointes.
        if (finalBlocks[i].isDiamond) continue;

        const fm = finalMasks[i];
        let bx1 = w, by1 = h, bx2 = 0, by2 = 0;
        for (let j = 0; j < n; j++) {
          if (!fm[j]) continue;
          const x = j % w, y = (j - x) / w;
          if (x < bx1) bx1 = x; if (y < by1) by1 = y;
          if (x > bx2) bx2 = x; if (y > by2) by2 = y;
        }

        const expanded = new Uint8Array(fm);
        let added = 0;
        // Ajout des pixels colorés non-assignés (antialiasing)
        for (let y = by1; y <= by2; y++) for (let x = bx1; x <= bx2; x++) {
          const j = y * w + x;
          if (expanded[j] || allBlocksMask[j]) continue;
          const o = j * 4;
          if (rgba[o] < 245 || rgba[o + 1] < 245 || rgba[o + 2] < 245) {
            expanded[j] = 255;
            added++;
          }
        }

        // Row-fill : pour chaque ligne, remplir entre le pixel masque le
        // plus à gauche et le plus à droite.  Comble les bulles blanches
        // internes quelle que soit leur taille, sans risque de débordement
        // vertical vers un bloc adjacent.
        let rowFilled = 0;
        for (let y = by1; y <= by2; y++) {
          let left = -1, right = -1;
          for (let x = bx1; x <= bx2; x++) {
            if (expanded[y * w + x]) { if (left < 0) left = x; right = x; }
          }
          if (left < 0 || right <= left) continue;
          for (let x = left; x <= right; x++) {
            const j = y * w + x;
            if (!expanded[j] && !allBlocksMask[j]) { expanded[j] = 255; rowFilled++; }
          }
        }
        if (added === 0 && rowFilled === 0) continue;

        const filled = fillExternal(expanded, w, h);

        let origArea = 0, newArea = 0;
        for (let j = 0; j < n; j++) {
          if (fm[j]) origArea++;
          if (filled[j]) newArea++;
        }
        // Garde-fou : le masque rempli ne doit pas dépasser
        // significativement la surface de la bbox (protection contre
        // le pontage accidentel vers un bloc voisin).
        const bboxArea = (bx2 - bx1 + 1) * (by2 - by1 + 1);
        if (newArea > bboxArea * 1.15) continue;

        finalMasks[i] = filled;
      }
    }

    // ── P6c : Nettoyage des résidus de blocs adjacents ──────────────
    // Les blocs Scratch empilés partagent des pixels d'antialiasing dans
    // la zone de connecteur. Retirer les pixels de teinte étrangère au
    // bord de chaque bloc non-conteneur.
    {
      for (let i = 0; i < finalBlocks.length; i++) {
        const [, , , bh] = finalBlocks[i].bbox;
        if (bh > CONTAINER_HEIGHT_THRESHOLD) continue;
        if (finalBlocks[i].isP6aChild) continue;

        const fm = finalMasks[i];

        // Teinte pure du corps (calculée avant P6b)
        const bodyHue = blockBodyHues[i];
        const TRIM_HUE_THRESH = 12;

        // Trim itératif depuis les bords : retirer les pixels de teinte
        // étrangère qui touchent l'extérieur du masque (max 5 passes)
        for (let pass = 0; pass < 5; pass++) {
          let removed = 0;
          for (let j = 0; j < n; j++) {
            if (!fm[j]) continue;
            if (hS[j] < 30) continue;
            let hd = Math.abs(hH[j] - bodyHue);
            hd = Math.min(hd, 180 - hd);
            if (hd <= TRIM_HUE_THRESH) continue;

            const x = j % w, y = (j - x) / w;
            let touchesBorder = false;
            for (const [dx, dy] of [[-1,0],[1,0],[0,-1],[0,1]]) {
              const nx = x + dx, ny = y + dy;
              if (nx < 0 || nx >= w || ny < 0 || ny >= h || !fm[ny * w + nx]) {
                touchesBorder = true; break;
              }
            }
            if (touchesBorder) { fm[j] = 0; removed++; }
          }
          if (removed === 0) break;
        }
      }
    }
    // ── P7 : Nettoyage final des masques ────────────────────────────
    // Retirer les pixels de bord qui sont blancs (fond) ou de teinte
    // étrangère (anti-aliasing, résidus de blocs adjacents).
    {
      const BG_THRESH = 240;
      const EDGE_HUE_THRESH = 25;
      const EDGE_MIN_SAT = 25;

      for (let bi = 0; bi < finalBlocks.length; bi++) {
        // Les diamants P6a ont un masque polygone parfait — ne pas nettoyer
        // (les pixels orange/blancs internes doivent être préservés)
        if (finalBlocks[bi].isP6aChild && finalBlocks[bi].isDiamond) continue;

        const fm = finalMasks[bi];
        const bodyHue = finalBlocks[bi].meanHue;

        for (let pass = 0; pass < 4; pass++) {
          let removed = 0;
          for (let j = 0; j < n; j++) {
            if (!fm[j]) continue;
            const o = j * 4;
            const r = rgba[o], g = rgba[o + 1], b = rgba[o + 2];
            const isWhite = r >= BG_THRESH && g >= BG_THRESH && b >= BG_THRESH;
            let isForeign = false;
            if (!isWhite && hS[j] >= EDGE_MIN_SAT) {
              let hd = Math.abs(hH[j] - bodyHue);
              hd = Math.min(hd, 180 - hd);
              isForeign = hd > EDGE_HUE_THRESH;
            }
            if (!isWhite && !isForeign) continue;
            const x = j % w, y = (j - x) / w;
            let touchesBorder = false;
            for (const [dx, dy] of [[-1,0],[1,0],[0,-1],[0,1]]) {
              const nx = x + dx, ny = y + dy;
              if (nx < 0 || nx >= w || ny < 0 || ny >= h || !fm[ny * w + nx]) {
                touchesBorder = true; break;
              }
            }
            if (touchesBorder) { fm[j] = 0; removed++; }
          }
          if (removed === 0) break;
        }
      }
    }

    // ── P7b : Élagage des connecteurs (tabs en haut/bas) ──────────
    // Les blocs Scratch ont des onglets de connexion (puzzle tabs)
    // en haut et en bas. Ces rangées étroites sont retirées.
    // Méthode : depuis le bas (puis le haut), on compare chaque
    // rangée à la rangée précédente ; un saut brutal (< 35%)
    // indique le début d'un connecteur → on coupe.
    {
      const DROP_RATIO = 0.35;

      for (let bi = 0; bi < finalBlocks.length; bi++) {
        const fm = finalMasks[bi];
        if (finalBlocks[bi].isDiamond) continue;

        // Largeur par ligne
        const rowW = new Int32Array(h);
        for (let y = 0; y < h; y++) {
          let cnt = 0;
          for (let x = 0; x < w; x++) if (fm[y * w + x]) cnt++;
          rowW[y] = cnt;
        }

        // Trim du bas : trouver la dernière rangée "pleine", puis
        // supprimer toutes les rangées en dessous qui sont < 35%
        let lastFullY = -1;
        for (let y = h - 1; y >= 0; y--) {
          if (rowW[y] > 0) { lastFullY = y; break; }
        }
        if (lastFullY < 0) continue;

        // Chercher la largeur de référence (max des 5 dernières rangées pleines avant le drop)
        let refW = 0;
        for (let y = lastFullY; y >= Math.max(0, lastFullY - 10); y--) {
          if (rowW[y] > refW) refW = rowW[y];
        }
        const threshBot = refW * DROP_RATIO;
        for (let y = lastFullY; y >= 0; y--) {
          if (rowW[y] === 0) continue;
          if (rowW[y] >= threshBot) break;
          for (let x = 0; x < w; x++) fm[y * w + x] = 0;
          rowW[y] = 0;
        }

        // Trim du haut : même logique
        let firstFullY = -1;
        for (let y = 0; y < h; y++) {
          if (rowW[y] > 0) { firstFullY = y; break; }
        }
        if (firstFullY < 0) continue;

        let refWTop = 0;
        for (let y = firstFullY; y <= Math.min(h - 1, firstFullY + 10); y++) {
          if (rowW[y] > refWTop) refWTop = rowW[y];
        }
        const threshTop = refWTop * DROP_RATIO;
        for (let y = firstFullY; y < h; y++) {
          if (rowW[y] === 0) continue;
          if (rowW[y] >= threshTop) break;
          for (let x = 0; x < w; x++) fm[y * w + x] = 0;
        }
      }
    }

    const manifest = {
      source: 'scratch',
      imageType: 'blocks',
      size: { w, h },
      blocks: []
    };

    const pad = 1; // Pad réduit pour Scratch (P7 nettoie les bords)
    const SCRATCH_MIN_BLOCK_H = 20;
    const SCRATCH_MIN_BLOCK_W = 20;

    // Premier pass : identifier les conteneurs et les blocs trop petits
    // Les blocs trop petits doivent réintégrer le conteneur parent
    const tooSmall = new Set();
    for (let i = 0; i < finalBlocks.length; i++) {
      const fm = finalMasks[i];
      let bx1 = w, by1 = h, bx2 = -1, by2 = -1;
      for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
        if (!fm[y * w + x]) continue;
        if (x < bx1) bx1 = x; if (y < by1) by1 = y;
        if (x > bx2) bx2 = x; if (y > by2) by2 = y;
      }
      if (bx2 < 0) { tooSmall.add(i); continue; }
      const bw = bx2 - bx1 + 1, bh = by2 - by1 + 1;
      if (bh > CONTAINER_HEIGHT_THRESHOLD) continue; // conteneur, garder
      if (bh < SCRATCH_MIN_BLOCK_H || bw < SCRATCH_MIN_BLOCK_W) tooSmall.add(i);
    }

    // Réintégrer les pixels des blocs trop petits dans le conteneur parent
    if (tooSmall.size > 0) {
      for (const si of tooSmall) {
        const sm = finalMasks[si];
        // Trouver le conteneur englobant (plus petit conteneur dont la bbox contient le bloc)
        let bestCI = -1, bestArea = Infinity;
        let sx1 = w, sy1 = h, sx2 = -1, sy2 = -1;
        for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
          if (!sm[y * w + x]) continue;
          if (x < sx1) sx1 = x; if (y < sy1) sy1 = y;
          if (x > sx2) sx2 = x; if (y > sy2) sy2 = y;
        }
        if (sx2 < 0) continue;
        for (let ci = 0; ci < finalBlocks.length; ci++) {
          if (tooSmall.has(ci)) continue;
          const [cbx, cby, cbw, cbh] = finalBlocks[ci].bbox;
          if (cbh <= CONTAINER_HEIGHT_THRESHOLD) continue; // pas un conteneur
          const margin = 6;
          if (sx1 >= cbx - margin && sy1 >= cby - margin &&
              sx2 <= cbx + cbw + margin && sy2 <= cby + cbh + margin) {
            const cArea = cbw * cbh;
            if (cArea < bestArea) { bestArea = cArea; bestCI = ci; }
          }
        }
        if (bestCI >= 0) {
          // Réintégrer dans le conteneur
          const cm = finalMasks[bestCI];
          for (let k = 0; k < n; k++) if (sm[k]) cm[k] = 255;
        }
      }
    }

    // ── P7b : Absorption universelle ─────────────────────────────────
    // Tout bloc non-conteneur, non-diamant dont le centre est à l'intérieur
    // d'un diamant → absorber dans le diamant.
    {
      for (let i = 0; i < finalBlocks.length; i++) {
        if (tooSmall.has(i)) continue;
        if (!finalBlocks[i].isDiamond) continue;
        const [dx, dy, dw, dh] = finalBlocks[i].bbox;
        for (let j = 0; j < finalBlocks.length; j++) {
          if (j === i || tooSmall.has(j)) continue;
          if (finalBlocks[j].isDiamond) continue;
          if (finalBlocks[j].bbox[3] > CONTAINER_HEIGHT_THRESHOLD) continue;
          const [bx, by, bw, bh] = finalBlocks[j].bbox;
          const bcx = bx + bw / 2, bcy = by + bh / 2;
          if (bcx >= dx && bcx <= dx + dw && bcy >= dy && bcy <= dy + dh) {
            const dm = finalMasks[i], sm = finalMasks[j];
            for (let p = 0; p < n; p++) if (sm[p]) { dm[p] = 255; sm[p] = 0; }
            tooSmall.add(j);
            let nx1 = w, ny1 = h, nx2 = -1, ny2 = -1;
            for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
              if (!dm[y * w + x]) continue;
              if (x < nx1) nx1 = x; if (y < ny1) ny1 = y;
              if (x > nx2) nx2 = x; if (y > ny2) ny2 = y;
            }
            finalBlocks[i].bbox = [nx1, ny1, nx2 - nx1 + 1, ny2 - ny1 + 1];
          }
        }
      }
    }

    // ── P7b : Blocs qui en CONTIENNENT un autre → conteneurs ─────────
    // Une fois les silhouettes complétées (P3d), un bloc Scratch qui accueille un
    // opérateur l'englobe entièrement (fillExternal a bouché le trou). On le traite
    // alors comme le « si » de MakeCode : il devient un CONTENEUR — il reste dans le
    // fond, avec un trou à la forme de l'enfant — et seul l'enfant est une pièce à
    // glisser. Sans ça, la pièce parente contenait l'image de son enfant et les deux
    // zones de dépôt se chevauchaient.
    {
      const CONTENU_MARGE = 4;
      for (let pi = 0; pi < finalBlocks.length; pi++) {
        if (tooSmall.has(pi)) continue;
        const [px, py, pw, ph] = finalBlocks[pi].bbox;
        for (let ci = 0; ci < finalBlocks.length; ci++) {
          if (ci === pi || tooSmall.has(ci)) continue;
          const [cx, cy, cw, ch] = finalBlocks[ci].bbox;
          if (cw * ch >= pw * ph) continue;
          if (cx < px - CONTENU_MARGE || cy < py - CONTENU_MARGE ||
              cx + cw > px + pw + CONTENU_MARGE || cy + ch > py + ph + CONTENU_MARGE) continue;
          // Test sur la COULEUR et non la teinte : les catégories Scratch ne sont
          // séparées que de 3 à 4 en teinte, mais de 30 à 43 en distance RGB.
          if (scratchMemeCouleur(finalBlocks[pi].meanColor, finalBlocks[ci].meanColor)) {
            // MÊME couleur : ce n'est pas un bloc imbriqué mais un MORCEAU du conteneur
            // (le bras du bas d'un « si … alors »). Il le rejoint au lieu de devenir une
            // pièce à glisser — sinon l'élève devait replacer l'encadrement lui-même.
            for (let k = 0; k < n; k++) if (finalMasks[ci][k]) finalMasks[pi][k] = 255;
            tooSmall.add(ci);
            continue;
          }
          // Le bord arrondi gauche de l'enfant est mitoyen de la colonne du conteneur :
          // ces pixels, pourtant exactement de la couleur de l'ENFANT, étaient restés au
          // conteneur et la pièce sortait rognée de 4-5 px. On les lui rend.
          const tonEnfant = scratchTonDominant(finalMasks[ci]);
          if (tonEnfant) {
            const [ex, ey, ew, eh] = finalBlocks[ci].bbox;
            const yMin = Math.max(0, ey - 2), yMax = Math.min(h - 1, ey + eh - 1 + 2);
            const xMin = Math.max(0, ex - 8), xMax = Math.min(w - 1, ex + ew - 1 + 8);
            for (let y = yMin; y <= yMax; y++) {
              for (let x = xMin; x <= xMax; x++) {
                const k = y * w + x;
                if (!finalMasks[pi][k] || finalMasks[ci][k]) continue;
                const o = k * 4;
                const dr = rgba[o] - tonEnfant[0], dg = rgba[o + 1] - tonEnfant[1], db = rgba[o + 2] - tonEnfant[2];
                if (dr * dr + dg * dg + db * db > 20 * 20) continue;
                finalMasks[ci][k] = 255;
              }
            }
            let mnx = w, mny = h, mxx = -1, mxy = -1;
            for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
              if (!finalMasks[ci][y * w + x]) continue;
              if (x < mnx) mnx = x; if (y < mny) mny = y;
              if (x > mxx) mxx = x; if (y > mxy) mxy = y;
            }
            if (mxx >= 0) finalBlocks[ci].bbox = [mnx, mny, mxx - mnx + 1, mxy - mny + 1];
          }
          const enfantDil = dilate(finalMasks[ci], w, h, 3, 3);
          for (let k = 0; k < n; k++) if (enfantDil[k]) finalMasks[pi][k] = 0;
          finalBlocks[pi].isContainer = true;
        }
      }
    }

    for (let i = 0; i < finalBlocks.length; i++) {
      if (tooSmall.has(i)) continue;
      // Recalculer bbox et area depuis le masque réel (après P7 nettoyage)
      const fm = finalMasks[i];
      let bx1 = w, by1 = h, bx2 = -1, by2 = -1, realArea = 0;
      for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
        if (!fm[y * w + x]) continue;
        realArea++;
        if (x < bx1) bx1 = x; if (y < by1) by1 = y;
        if (x > bx2) bx2 = x; if (y > by2) by2 = y;
      }
      if (bx2 < 0) continue;
      const bx = bx1, by = by1, bw = bx2 - bx1 + 1, bh = by2 - by1 + 1;
      const x1 = Math.max(0, bx - pad), y1 = Math.max(0, by - pad);
      const x2 = Math.min(w, bx + bw + pad), y2 = Math.min(h, by + bh + pad);

      let blockType;
      if (finalBlocks[i].isDiamond) blockType = 'diamond';
      else if (finalBlocks[i].isContainer || bh > CONTAINER_HEIGHT_THRESHOLD) blockType = 'container';
      else blockType = 'block';

      manifest.blocks.push({
        id: i,
        pos: { x: x1, y: y1 },
        size: { w: x2 - x1, h: y2 - y1 },
        color_bgr: [
          Math.round(finalBlocks[i].meanColor[2]),
          Math.round(finalBlocks[i].meanColor[1]),
          Math.round(finalBlocks[i].meanColor[0])
        ],
        area: realArea,
        type: blockType
      });
    }

    phases.p6_n_blocks = manifest.blocks.length;
    phases.p6_bboxes = finalBlocks.map(b => [...b.bbox]);

    console.log('[MKExtract] extractScratch done:', manifest.blocks.length, 'blocks',
      manifest.blocks.map(b => b.type + ' pos=(' + b.pos.x + ',' + b.pos.y + ') size=(' + b.size.w + 'x' + b.size.h + ')').join(' | '));

    return { manifest, blockMasks: finalMasks, bgColor: bgColor.map(Math.round), phases, _version: '2026-08-09-scratch-couleur-categories' };
  }

  // ═══════════════════════════════════════════════════════════════════
  // POINT D'ENTRÉE PRINCIPAL — détection auto + routage
  // ═══════════════════════════════════════════════════════════════════
  function extract(imageData) {
    const w = imageData.width, h = imageData.height;
    const rgba = imageData.data;
    const hsv = toHSV(rgba, w, h);
    const imageType = detectImageType(hsv.S, w, h);

    if (imageType === 'flowchart') {
      return extractFlowchart(imageData);
    } else {
      const srcInfo = detectSource(rgba, w, h);
      if (srcInfo && srcInfo.source === 'scratch') {
        return extractScratch(imageData);
      }
      return extractBlocks(imageData, srcInfo);
    }
  }

  // ═══════════════════════════════════════════════════════════════════
  // API PUBLIQUE
  // ═══════════════════════════════════════════════════════════════════
  return { extract };
})();

if (typeof module !== 'undefined' && module.exports) module.exports = MKExtract;
