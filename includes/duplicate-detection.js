// Détection des étiquettes visuellement identiques pour l'extraction automatique drag-déposer.
// Signature : carte de densité de texte (24×24 grayscale en RGB), avec filtre des pixels isolés
// puis dilatation 1-px du masque texte pour absorber les micro-décalages d'extraction.
// Distance : fraction de cellules significativement différentes.

(function (global) {
    'use strict';

    var SIG_SIZE = 24;
    var SIG_BYTES = SIG_SIZE * SIG_SIZE * 3;
    var LUM_TEXT_BRIGHT = 220;
    var LUM_TEXT_DARK = 50;
    var BBOX_MARGIN = 2;
    var MIN_TEXT_PIXELS = 10;
    var DILATE_RADIUS = 1;
    var DEFAULT_PER_CELL_THRESHOLD = 80;
    var DEFAULT_RATIO_THRESHOLD = 0.25;
    var DEFAULT_DIM_RATIO_MAX = 1.5;
    // isParametrable
    var PARAM_WHITE_LUM_THRESH = 240;
    var PARAM_MIN_OVAL_PIXELS = 50;

    function buildTextMask(rgba, w, h) {
        var n = w * h;
        var mask = new Uint8Array(n);
        var count = 0;

        // 1. Texte clair (lum > 220)
        for (var py = 0; py < h; py++) {
            for (var px = 0; px < w; px++) {
                var o = (py * w + px) * 4;
                if (rgba[o + 3] > 0) {
                    var lum = 0.299 * rgba[o] + 0.587 * rgba[o + 1] + 0.114 * rgba[o + 2];
                    if (lum > LUM_TEXT_BRIGHT) {
                        mask[py * w + px] = 1;
                        count++;
                    }
                }
            }
        }

        // 2. Fallback texte foncé (lum < 50)
        if (count < MIN_TEXT_PIXELS) {
            mask = new Uint8Array(n);
            count = 0;
            for (var py2 = 0; py2 < h; py2++) {
                for (var px2 = 0; px2 < w; px2++) {
                    var o2 = (py2 * w + px2) * 4;
                    if (rgba[o2 + 3] > 0) {
                        var lum2 = 0.299 * rgba[o2] + 0.587 * rgba[o2 + 1] + 0.114 * rgba[o2 + 2];
                        if (lum2 < LUM_TEXT_DARK) {
                            mask[py2 * w + px2] = 1;
                            count++;
                        }
                    }
                }
            }
        }

        // 3. Dernier fallback : tous les pixels opaques
        if (count < MIN_TEXT_PIXELS) {
            mask = new Uint8Array(n);
            count = 0;
            for (var py3 = 0; py3 < h; py3++) {
                for (var px3 = 0; px3 < w; px3++) {
                    var o3 = (py3 * w + px3) * 4;
                    if (rgba[o3 + 3] > 0) {
                        mask[py3 * w + px3] = 1;
                        count++;
                    }
                }
            }
        }

        return { mask: mask, count: count };
    }

    // Filtre les pixels isolés du masque (bruit d'anti-aliasing en bord de bloc).
    function removeIsolated(mask, w, h, minNeighbors) {
        var out = new Uint8Array(w * h);
        for (var y = 0; y < h; y++) {
            for (var x = 0; x < w; x++) {
                if (!mask[y * w + x]) continue;
                var nb = 0;
                for (var dy = -1; dy <= 1; dy++) {
                    for (var dx = -1; dx <= 1; dx++) {
                        if (dx === 0 && dy === 0) continue;
                        var ny = y + dy, nx = x + dx;
                        if (ny >= 0 && ny < h && nx >= 0 && nx < w && mask[ny * w + nx]) nb++;
                    }
                }
                if (nb >= minNeighbors) out[y * w + x] = 1;
            }
        }
        return out;
    }

    // Dilatation morphologique simple (boîte 2r+1) pour absorber les micro-décalages
    // entre deux extractions du même bloc.
    function dilateMask(mask, w, h, radius) {
        if (radius <= 0) return mask;
        var out = new Uint8Array(w * h);
        for (var y = 0; y < h; y++) {
            for (var x = 0; x < w; x++) {
                var on = false;
                for (var dy = -radius; dy <= radius && !on; dy++) {
                    for (var dx = -radius; dx <= radius && !on; dx++) {
                        var ny = y + dy, nx = x + dx;
                        if (ny >= 0 && ny < h && nx >= 0 && nx < w && mask[ny * w + nx]) on = true;
                    }
                }
                out[y * w + x] = on ? 1 : 0;
            }
        }
        return out;
    }

    function computeBlockSignature(rgba, w, h) {
        var sig = new Uint8Array(SIG_BYTES);

        var built = buildTextMask(rgba, w, h);
        var textMask = built.mask;
        if (built.count === 0) return sig; // entièrement vide → signature à 0
        textMask = removeIsolated(textMask, w, h, 2);
        textMask = dilateMask(textMask, w, h, DILATE_RADIUS);

        // Bbox du masque texte
        var minX = w, minY = h, maxX = -1, maxY = -1;
        for (var py = 0; py < h; py++) {
            for (var px = 0; px < w; px++) {
                if (textMask[py * w + px]) {
                    if (px < minX) minX = px;
                    if (py < minY) minY = py;
                    if (px > maxX) maxX = px;
                    if (py > maxY) maxY = py;
                }
            }
        }
        if (maxX < 0) return sig;

        minX = Math.max(0, minX - BBOX_MARGIN);
        minY = Math.max(0, minY - BBOX_MARGIN);
        maxX = Math.min(w - 1, maxX + BBOX_MARGIN);
        maxY = Math.min(h - 1, maxY + BBOX_MARGIN);
        var bw = maxX - minX + 1, bh = maxY - minY + 1;

        // Resampling : densité de pixels-texte par cellule (0-255)
        for (var cy = 0; cy < SIG_SIZE; cy++) {
            for (var cx = 0; cx < SIG_SIZE; cx++) {
                var x0 = minX + Math.floor(cx * bw / SIG_SIZE);
                var x1 = minX + Math.floor((cx + 1) * bw / SIG_SIZE);
                var y0 = minY + Math.floor(cy * bh / SIG_SIZE);
                var y1 = minY + Math.floor((cy + 1) * bh / SIG_SIZE);
                if (x1 <= x0) x1 = x0 + 1;
                if (y1 <= y0) y1 = y0 + 1;
                var textPx = 0, totalPx = 0;
                for (var ipy = y0; ipy < y1; ipy++) {
                    for (var ipx = x0; ipx < x1; ipx++) {
                        if (ipy >= h || ipx >= w) continue;
                        totalPx++;
                        if (textMask[ipy * w + ipx]) textPx++;
                    }
                }
                var density = totalPx > 0 ? Math.round(255 * textPx / totalPx) : 0;
                var so = (cy * SIG_SIZE + cx) * 3;
                sig[so] = density;
                sig[so + 1] = density;
                sig[so + 2] = density;
            }
        }
        return sig;
    }

    // Conservée pour compatibilité ; non utilisée par thresholdClustering désormais.
    function signatureDistanceL1(a, b) {
        if (!a || !b || a.length !== b.length) return Infinity;
        var sum = 0;
        for (var i = 0; i < a.length; i++) {
            var d = a[i] - b[i];
            sum += d < 0 ? -d : d;
        }
        return sum / a.length;
    }

    // Fraction de cellules dont la distance Manhattan dépasse perCellThreshold.
    // Non diluée par les cellules identiques, contrairement à L1 moyenné.
    function signatureCellDiffRatio(a, b, perCellThreshold) {
        if (!a || !b || a.length !== b.length) return 1.0;
        if (perCellThreshold === undefined) perCellThreshold = DEFAULT_PER_CELL_THRESHOLD;
        var diff = 0, total = 0;
        for (var i = 0; i < a.length; i += 3) {
            var d = Math.abs(a[i] - b[i]) + Math.abs(a[i + 1] - b[i + 1]) + Math.abs(a[i + 2] - b[i + 2]);
            if (d > perCellThreshold) diff++;
            total++;
        }
        return total > 0 ? diff / total : 1.0;
    }

    function sigFromCanvas(canvas) {
        var w = canvas.width, h = canvas.height;
        if (!w || !h) return new Uint8Array(SIG_BYTES);
        var ctx = canvas.getContext('2d');
        var img = ctx.getImageData(0, 0, w, h);
        return computeBlockSignature(img.data, w, h);
    }

    // Détecte les blocs "paramétrables" (qui contiennent ≥1 zone blanche pleine connexe
    // suffisamment grande, typiquement un ovale-paramètre ou un dropdown). Ces blocs ne
    // doivent jamais être groupés car ils peuvent partager la même structure mais avoir
    // des valeurs paramètres différentes (servo angle 80 vs 10, vitesse 20 vs 50, etc.).
    // Sans cette protection, mon algo donne des distances très basses pour de tels blocs
    // (ex : "vitesse roues 20/50" vs "vitesse roues 50/20" → 0.005, faux positif).
    function isParametrable(rgba, w, h) {
        var n = w * h;
        var whiteMask = new Uint8Array(n);
        for (var i = 0; i < n; i++) {
            var o = i * 4;
            if (rgba[o + 3] === 0) continue;
            var lum = 0.299 * rgba[o] + 0.587 * rgba[o + 1] + 0.114 * rgba[o + 2];
            if (lum > PARAM_WHITE_LUM_THRESH) whiteMask[i] = 1;
        }
        var visited = new Uint8Array(n);
        var stack = new Int32Array(n);
        for (var y = 0; y < h; y++) {
            for (var x = 0; x < w; x++) {
                var idx = y * w + x;
                if (!whiteMask[idx] || visited[idx]) continue;
                var sp = 0;
                stack[sp++] = idx;
                visited[idx] = 1;
                var count = 0;
                while (sp > 0) {
                    var c = stack[--sp];
                    count++;
                    if (count >= PARAM_MIN_OVAL_PIXELS) return true;
                    var cx = c % w, cy = (c - cx) / w;
                    if (cx > 0 && whiteMask[c - 1] && !visited[c - 1]) { visited[c - 1] = 1; stack[sp++] = c - 1; }
                    if (cx < w - 1 && whiteMask[c + 1] && !visited[c + 1]) { visited[c + 1] = 1; stack[sp++] = c + 1; }
                    if (cy > 0 && whiteMask[c - w] && !visited[c - w]) { visited[c - w] = 1; stack[sp++] = c - w; }
                    if (cy < h - 1 && whiteMask[c + w] && !visited[c + w]) { visited[c + w] = 1; stack[sp++] = c + w; }
                }
            }
        }
        return false;
    }

    function paramFromCanvas(canvas) {
        var w = canvas.width, h = canvas.height;
        if (!w || !h) return false;
        var ctx = canvas.getContext('2d');
        var img = ctx.getImageData(0, 0, w, h);
        return isParametrable(img.data, w, h);
    }

    // params (optionnel) : tableau bool[] indiquant si chaque label est paramétrable.
    // Une paire dont l'un des deux est paramétrable n'est jamais groupée (singleton garanti).
    function thresholdClustering(labels, sigs, distThreshold, dimRatioMax, params) {
        if (distThreshold === undefined) distThreshold = DEFAULT_RATIO_THRESHOLD;
        if (dimRatioMax === undefined) dimRatioMax = DEFAULT_DIM_RATIO_MAX;
        var n = labels.length;
        var parent = new Array(n);
        for (var p = 0; p < n; p++) parent[p] = p;

        function find(x) {
            while (parent[x] !== x) {
                parent[x] = parent[parent[x]];
                x = parent[x];
            }
            return x;
        }
        function union(a, b) {
            var ra = find(a), rb = find(b);
            if (ra !== rb) parent[ra] = rb;
        }

        for (var i = 0; i < n; i++) {
            for (var j = i + 1; j < n; j++) {
                if (params && (params[i] || params[j])) continue; // un des deux paramétrable → jamais groupés
                var wi = labels[i].size.w, hi = labels[i].size.h;
                var wj = labels[j].size.w, hj = labels[j].size.h;
                if (Math.max(wi, wj) / Math.min(wi, wj) > dimRatioMax) continue;
                if (Math.max(hi, hj) / Math.min(hi, hj) > dimRatioMax) continue;
                if (signatureCellDiffRatio(sigs[i], sigs[j]) < distThreshold) {
                    union(i, j);
                }
            }
        }

        var byRoot = {};
        for (var k = 0; k < n; k++) {
            var r = find(k);
            if (!byRoot[r]) byRoot[r] = [];
            byRoot[r].push(k);
        }
        var groups = [];
        for (var key in byRoot) {
            if (Object.prototype.hasOwnProperty.call(byRoot, key)) groups.push(byRoot[key]);
        }
        return groups;
    }

    // Repeint les copies non-ancres sur bgCanvas et retourne la liste réduite des indices conservés.
    // Ancre = première étiquette du groupe par tri (pos.y, pos.x) croissant.
    function mergeDuplicatesIntoBgCanvas(bgCanvas, labelCanvases, labels, groups) {
        var toRemove = {};
        var pasteList = [];

        groups.forEach(function (g) {
            if (g.length < 2) return;
            var sorted = g.slice().sort(function (a, b) {
                var la = labels[a], lb = labels[b];
                if (la.pos.y !== lb.pos.y) return la.pos.y - lb.pos.y;
                return la.pos.x - lb.pos.x;
            });
            for (var k = 1; k < sorted.length; k++) {
                toRemove[sorted[k]] = true;
                pasteList.push(sorted[k]);
            }
        });

        if (pasteList.length > 0) {
            var ctx = bgCanvas.getContext('2d');
            pasteList.forEach(function (li) {
                var lbl = labels[li];
                ctx.drawImage(labelCanvases[li], lbl.pos.x, lbl.pos.y);
            });
        }

        var keptIndices = [];
        for (var i = 0; i < labels.length; i++) {
            if (!toRemove[i]) keptIndices.push(i);
        }
        return { keptIndices: keptIndices };
    }

    global.computeBlockSignature = computeBlockSignature;
    global.signatureDistanceL1 = signatureDistanceL1;
    global.signatureCellDiffRatio = signatureCellDiffRatio;
    global.sigFromCanvas = sigFromCanvas;
    global.isParametrable = isParametrable;
    global.paramFromCanvas = paramFromCanvas;
    global.thresholdClustering = thresholdClustering;
    global.mergeDuplicatesIntoBgCanvas = mergeDuplicatesIntoBgCanvas;
})(window);
