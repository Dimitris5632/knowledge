// Knowledge: σκανάρισμα εγγράφων με την κάμερα του κινητού.
// Όλα γίνονται ΜΕΣΑ στη συσκευή: οι φωτογραφίες δεν στέλνονται πουθενά μέχρι να πατήσεις «Ανέβασμα».
// 1. Φωτογραφίζεις σελίδα  2. Σέρνεις τις 4 γωνίες στις άκρες του χαρτιού  3. Η σελίδα «ισιώνει»
// 4. Φίλτρο (φωτοτυπία / γκρι / χρώμα / ασπρόμαυρο)  5. Όλες οι σελίδες γίνονται ένα PDF.
(function () {
    'use strict';

    const panel = document.getElementById('scanner');
    if (!panel) { return; }

    const docInput   = document.querySelector('input[name="document"]');
    const pagesBox   = document.getElementById('scan-pages');
    const stepPages  = panel.querySelector('[data-step="pages"]');
    const stepEdit   = panel.querySelector('[data-step="edit"]');
    const canvas     = document.getElementById('scan-canvas');
    const ctx        = canvas.getContext('2d');
    const filterBox  = document.getElementById('scan-filter');
    const makeButton = document.getElementById('scan-make');
    const status     = document.getElementById('scan-status');
    const ready      = document.getElementById('scan-ready');

    const MAX_SOURCE = 2400;    // μεγαλύτερη πλευρά φωτογραφίας που κρατάμε
    const MAX_PAGE   = 1700;    // μεγαλύτερη πλευρά τελικής σελίδας (περίπου 200 dpi σε Α4)
    const MAX_BYTES  = 10 * 1024 * 1024;

    let pages = [];             // [{blob, width, height, url}]
    let source = null;          // καμβάς με τη φωτογραφία (μετά την περιστροφή)
    let corners = [];           // 4 γωνίες σε συντεταγμένες της φωτογραφίας: πάνω-αριστερά, πάνω-δεξιά, κάτω-δεξιά, κάτω-αριστερά
    let scale = 1;
    let dragging = -1;

    // Μηνύματα στα αγγλικά, όταν το site είναι σε EN
    const EN = {
        'Ίσιωμα σελίδας…': 'Straightening page…',
        'Η σελίδα ': 'Page ',
        ' προστέθηκε. Φωτογράφισε την επόμενη ή πάτα «Δημιουργία PDF».': ' added. Photograph the next one or tap “Create PDF”.',
        'Δημιουργία PDF…': 'Creating PDF…',
        '📄 Δημιουργία PDF': '📄 Create PDF',
        ' σελίδα)': ' page)', ' σελίδες)': ' pages)',
        ' σελίδα, ': ' page, ', ' σελίδες, ': ' pages, ',
        'Σελίδα ': 'Page ',
        'Πάνω': 'Up', 'Κάτω': 'Down', 'Διαγραφή': 'Delete',
        'Το PDF βγήκε ': 'The PDF is ',
        ', πάνω από το όριο των 10 MB. Αφαίρεσε κάποιες σελίδες ή διάλεξε «Ασπρόμαυρο».': ', over the 10 MB limit. Remove some pages or choose “Black & white”.',
        '✓ Έτοιμο: ': '✓ Ready: ',
        '). Συμπλήρωσε τα υπόλοιπα και πάτα «Ανέβασμα».': '). Fill in the rest and tap “Upload”.',
        'Το PDF κατέβηκε στη συσκευή σου. Επίλεξέ το στο πεδίο «Αρχείο».': 'The PDF was downloaded to your device. Select it in the “File” field.',
    };
    function _(text) { return (window.KB_LANG === 'en' && EN[text]) || text; }

    function say(text) { status.textContent = text; }

    function open()  { panel.hidden = false; document.body.classList.add('scanner-open'); showStep('pages'); }
    function close() { panel.hidden = true;  document.body.classList.remove('scanner-open'); }
    function showStep(step) {
        stepPages.hidden = step !== 'pages';
        stepEdit.hidden  = step !== 'edit';
        if (step === 'edit') { requestAnimationFrame(draw); }
    }

    // ---------- 1. Φόρτωση φωτογραφίας ----------
    async function loadFile(file) {
        let bitmap;
        try {
            bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        } catch (e) {
            bitmap = await new Promise(function (resolve, reject) {
                const img = new Image();
                img.onload = function () { resolve(img); };
                img.onerror = reject;
                img.src = URL.createObjectURL(file);
            });
        }
        const ratio = Math.min(1, MAX_SOURCE / Math.max(bitmap.width, bitmap.height));
        source = document.createElement('canvas');
        source.width  = Math.round(bitmap.width * ratio);
        source.height = Math.round(bitmap.height * ratio);
        source.getContext('2d').drawImage(bitmap, 0, 0, source.width, source.height);
        resetCorners();
        showStep('edit');
    }

    function resetCorners() {
        const w = source.width, h = source.height, m = 0.04;
        corners = [[w * m, h * m], [w * (1 - m), h * m], [w * (1 - m), h * (1 - m)], [w * m, h * (1 - m)]];
    }

    function rotate() {
        const turned = document.createElement('canvas');
        turned.width = source.height;
        turned.height = source.width;
        const c = turned.getContext('2d');
        c.translate(turned.width, 0);
        c.rotate(Math.PI / 2);
        c.drawImage(source, 0, 0);
        source = turned;
        resetCorners();
        draw();
    }

    // ---------- 2. Οθόνη επεξεργασίας με τις 4 γωνίες ----------
    function draw() {
        if (!source) { return; }
        const stage = canvas.parentElement;
        const maxW = stage.clientWidth || 320;
        const maxH = Math.max(240, window.innerHeight * 0.58);
        scale = Math.min(maxW / source.width, maxH / source.height);
        canvas.width  = Math.round(source.width * scale);
        canvas.height = Math.round(source.height * scale);
        ctx.drawImage(source, 0, 0, canvas.width, canvas.height);

        // σκοτεινιάζουμε ό,τι είναι έξω από το χαρτί
        ctx.save();
        ctx.fillStyle = 'rgba(0, 0, 0, 0.45)';
        ctx.beginPath();
        ctx.rect(0, 0, canvas.width, canvas.height);
        corners.slice().reverse().forEach(function (p, i) {
            i === 0 ? ctx.moveTo(p[0] * scale, p[1] * scale) : ctx.lineTo(p[0] * scale, p[1] * scale);
        });
        ctx.closePath();
        ctx.fill('evenodd');
        ctx.restore();

        ctx.strokeStyle = '#e08a4a';
        ctx.lineWidth = 3;
        ctx.beginPath();
        corners.forEach(function (p, i) {
            i === 0 ? ctx.moveTo(p[0] * scale, p[1] * scale) : ctx.lineTo(p[0] * scale, p[1] * scale);
        });
        ctx.closePath();
        ctx.stroke();

        corners.forEach(function (p) {
            ctx.beginPath();
            ctx.arc(p[0] * scale, p[1] * scale, 13, 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(224, 138, 74, 0.85)';
            ctx.fill();
            ctx.lineWidth = 2;
            ctx.strokeStyle = '#fff';
            ctx.stroke();
        });
    }

    function pointer(event) {
        const r = canvas.getBoundingClientRect();
        return [(event.clientX - r.left) * (canvas.width / r.width), (event.clientY - r.top) * (canvas.height / r.height)];
    }

    canvas.addEventListener('pointerdown', function (event) {
        const p = pointer(event);
        let best = -1, bestDistance = 40;
        corners.forEach(function (c, i) {
            const d = Math.hypot(c[0] * scale - p[0], c[1] * scale - p[1]);
            if (d < bestDistance) { best = i; bestDistance = d; }
        });
        if (best >= 0) {
            dragging = best;
            canvas.setPointerCapture(event.pointerId);
            event.preventDefault();
        }
    });
    canvas.addEventListener('pointermove', function (event) {
        if (dragging < 0) { return; }
        const p = pointer(event);
        corners[dragging] = [
            Math.min(source.width, Math.max(0, p[0] / scale)),
            Math.min(source.height, Math.max(0, p[1] / scale)),
        ];
        draw();
        event.preventDefault();
    });
    ['pointerup', 'pointercancel'].forEach(function (name) {
        canvas.addEventListener(name, function () { dragging = -1; });
    });
    window.addEventListener('resize', function () { if (!stepEdit.hidden) { draw(); } });

    // ---------- 3. Ίσιωμα σελίδας (προοπτική) ----------
    // Βρίσκουμε τον πίνακα που στέλνει το ορθογώνιο της τελικής σελίδας πάνω στο τετράπλευρο της φωτογραφίας.
    function homography(from, to) {
        const A = [], b = [];
        for (let i = 0; i < 4; i++) {
            const x = from[i][0], y = from[i][1], u = to[i][0], v = to[i][1];
            A.push([x, y, 1, 0, 0, 0, -u * x, -u * y]); b.push(u);
            A.push([0, 0, 0, x, y, 1, -v * x, -v * y]); b.push(v);
        }
        // απαλοιφή Gauss
        for (let col = 0; col < 8; col++) {
            let pivot = col;
            for (let row = col + 1; row < 8; row++) {
                if (Math.abs(A[row][col]) > Math.abs(A[pivot][col])) { pivot = row; }
            }
            [A[col], A[pivot]] = [A[pivot], A[col]];
            [b[col], b[pivot]] = [b[pivot], b[col]];
            for (let row = 0; row < 8; row++) {
                if (row === col) { continue; }
                const f = A[row][col] / A[col][col];
                for (let k = col; k < 8; k++) { A[row][k] -= f * A[col][k]; }
                b[row] -= f * b[col];
            }
        }
        return b.map(function (value, i) { return value / A[i][i]; });
    }

    function flatten() {
        const d = function (a, b) { return Math.hypot(a[0] - b[0], a[1] - b[1]); };
        let w = (d(corners[0], corners[1]) + d(corners[3], corners[2])) / 2;
        let h = (d(corners[0], corners[3]) + d(corners[1], corners[2])) / 2;
        const ratio = Math.min(1, MAX_PAGE / Math.max(w, h));
        w = Math.max(50, Math.round(w * ratio));
        h = Math.max(50, Math.round(h * ratio));

        const H = homography([[0, 0], [w, 0], [w, h], [0, h]], corners);
        const src = source.getContext('2d').getImageData(0, 0, source.width, source.height);
        const sw = source.width, sh = source.height, sd = src.data;
        const out = new ImageData(w, h), od = out.data;

        for (let y = 0; y < h; y++) {
            for (let x = 0; x < w; x++) {
                const z = H[6] * x + H[7] * y + 1;
                let u = (H[0] * x + H[1] * y + H[2]) / z;
                let v = (H[3] * x + H[4] * y + H[5]) / z;
                u = Math.min(sw - 1.001, Math.max(0, u));
                v = Math.min(sh - 1.001, Math.max(0, v));
                const x0 = u | 0, y0 = v | 0, fx = u - x0, fy = v - y0;
                const i00 = (y0 * sw + x0) * 4, i10 = i00 + 4, i01 = i00 + sw * 4, i11 = i01 + 4;
                const o = (y * w + x) * 4;
                for (let c = 0; c < 3; c++) {      // διγραμμική παρεμβολή για καθαρά γράμματα
                    od[o + c] = (sd[i00 + c] * (1 - fx) + sd[i10 + c] * fx) * (1 - fy)
                              + (sd[i01 + c] * (1 - fx) + sd[i11 + c] * fx) * fy;
                }
                od[o + 3] = 255;
            }
        }
        return out;
    }

    // ---------- 4. Φίλτρα ----------
    function applyFilter(image, mode) {
        const d = image.data;
        if (mode === 'color') { return image; }
        const gray = new Uint8ClampedArray(d.length / 4);
        const histogram = new Array(256).fill(0);
        for (let i = 0, j = 0; i < d.length; i += 4, j++) {
            gray[j] = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
            histogram[gray[j]]++;
        }
        let low = 0, high = 255;
        if (mode === 'clean') {
            // «Φωτοτυπία»: το χαρτί γίνεται λευκό και τα γράμματα πιο σκούρα
            const total = gray.length;
            let sum = 0;
            for (let k = 0; k < 256; k++) { sum += histogram[k]; if (sum >= total * 0.02) { low = k; break; } }
            sum = 0;
            for (let k = 0; k < 256; k++) { sum += histogram[k]; if (sum >= total * 0.80) { high = k; break; } }
            high = Math.max(high, low + 40);
        }
        let threshold = 128;
        if (mode === 'bw') {
            // Otsu: βρίσκει μόνο του το καλύτερο όριο ανάμεσα σε «χαρτί» και «μελάνι»
            const total = gray.length;
            let sumAll = 0;
            for (let k = 0; k < 256; k++) { sumAll += k * histogram[k]; }
            let sumB = 0, weightB = 0, best = 0;
            for (let k = 0; k < 256; k++) {
                weightB += histogram[k];
                if (!weightB) { continue; }
                const weightF = total - weightB;
                if (!weightF) { break; }
                sumB += k * histogram[k];
                const between = weightB * weightF * Math.pow(sumB / weightB - (sumAll - sumB) / weightF, 2);
                if (between > best) { best = between; threshold = k; }
            }
        }
        for (let i = 0, j = 0; i < d.length; i += 4, j++) {
            let g = gray[j];
            if (mode === 'clean') {
                g = Math.pow(Math.min(1, Math.max(0, (g - low) / (high - low))), 1.4) * 255;
            } else if (mode === 'bw') {
                g = g > threshold ? 255 : 0;
            }
            d[i] = d[i + 1] = d[i + 2] = g;
        }
        return image;
    }

    async function savePage() {
        say(_('Ίσιωμα σελίδας…'));
        await new Promise(function (r) { setTimeout(r, 30); });     // να προλάβει να φανεί το μήνυμα
        const image = applyFilter(flatten(), filterBox.value);
        const out = document.createElement('canvas');
        out.width = image.width;
        out.height = image.height;
        out.getContext('2d').putImageData(image, 0, 0);
        const blob = await new Promise(function (r) { out.toBlob(r, 'image/jpeg', 0.8); });
        pages.push({ blob: blob, width: image.width, height: image.height, url: URL.createObjectURL(blob) });
        source = null;
        renderPages();
        showStep('pages');
        say(_('Η σελίδα ') + pages.length + _(' προστέθηκε. Φωτογράφισε την επόμενη ή πάτα «Δημιουργία PDF».'));
    }

    // ---------- Λίστα σελίδων ----------
    function renderPages() {
        pagesBox.innerHTML = '';
        pages.forEach(function (page, i) {
            const item = document.createElement('div');
            item.className = 'scan-page';
            const img = document.createElement('img');
            img.src = page.url;
            img.alt = _('Σελίδα ') + (i + 1);
            const label = document.createElement('span');
            label.textContent = (i + 1);
            const tools = document.createElement('div');
            [['▲', _('Πάνω'), -1], ['▼', _('Κάτω'), 1], ['✕', _('Διαγραφή'), 0]].forEach(function (t) {
                const b = document.createElement('button');
                b.type = 'button';
                b.textContent = t[0];
                b.title = t[1];
                b.addEventListener('click', function () {
                    if (t[2] === 0) {
                        URL.revokeObjectURL(page.url);
                        pages.splice(i, 1);
                    } else {
                        const j = i + t[2];
                        if (j < 0 || j >= pages.length) { return; }
                        [pages[i], pages[j]] = [pages[j], pages[i]];
                    }
                    renderPages();
                });
                tools.appendChild(b);
            });
            item.append(img, label, tools);
            pagesBox.appendChild(item);
        });
        makeButton.disabled = pages.length === 0;
        makeButton.textContent = _('📄 Δημιουργία PDF') + (pages.length ? ' (' + pages.length + (pages.length === 1 ? _(' σελίδα)') : _(' σελίδες)')) : '');
    }

    // ---------- 5. PDF ----------
    // Μικρό «χειροποίητο» PDF: κάθε σελίδα είναι μια εικόνα JPEG. Δεν χρειάζεται καμία εξωτερική βιβλιοθήκη.
    async function buildPdf() {
        const encoder = new TextEncoder();
        const chunks = [], offsets = [];
        let length = 0;
        const add = function (part) {
            const bytes = typeof part === 'string' ? encoder.encode(part) : part;
            chunks.push(bytes);
            length += bytes.length;
        };
        const object = function (number, body) { offsets[number] = length; add(number + ' 0 obj\n'); body(); add('\nendobj\n'); };

        add('%PDF-1.4\n');
        const total = 2 + pages.length * 3;
        const kids = pages.map(function (p, i) { return (3 + i * 3) + ' 0 R'; }).join(' ');

        object(1, function () { add('<< /Type /Catalog /Pages 2 0 R >>'); });
        object(2, function () { add('<< /Type /Pages /Kids [' + kids + '] /Count ' + pages.length + ' >>'); });

        for (let i = 0; i < pages.length; i++) {
            const page = pages[i];
            const base = 3 + i * 3;
            const W = 595.28;                                   // πλάτος Α4 σε στιγμές
            const H = +(W * page.height / page.width).toFixed(2);
            const jpeg = new Uint8Array(await page.blob.arrayBuffer());
            const content = 'q ' + W + ' 0 0 ' + H + ' 0 0 cm /Im0 Do Q';

            object(base, function () {
                add('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + W + ' ' + H + '] '
                    + '/Resources << /XObject << /Im0 ' + (base + 2) + ' 0 R >> >> /Contents ' + (base + 1) + ' 0 R >>');
            });
            object(base + 1, function () {
                add('<< /Length ' + content.length + ' >>\nstream\n' + content + '\nendstream');
            });
            object(base + 2, function () {
                add('<< /Type /XObject /Subtype /Image /Width ' + page.width + ' /Height ' + page.height
                    + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + jpeg.length + ' >>\nstream\n');
                add(jpeg);
                add('\nendstream');
            });
        }

        const xref = length;
        let table = 'xref\n0 ' + (total + 1) + '\n0000000000 65535 f \n';
        for (let n = 1; n <= total; n++) {
            table += String(offsets[n]).padStart(10, '0') + ' 00000 n \n';
        }
        add(table + 'trailer\n<< /Size ' + (total + 1) + ' /Root 1 0 R >>\nstartxref\n' + xref + '\n%%EOF\n');
        return new Blob(chunks, { type: 'application/pdf' });
    }

    async function makePdf() {
        if (!pages.length) { return; }
        say(_('Δημιουργία PDF…'));
        const pdf = await buildPdf();
        const date = new Date().toISOString().slice(0, 10);
        const file = new File([pdf], 'scan-' + date + '.pdf', { type: 'application/pdf' });
        const size = (pdf.size / 1048576).toFixed(1).replace('.', ',') + ' MB';

        if (pdf.size > MAX_BYTES) {
            say(_('Το PDF βγήκε ') + size + _(', πάνω από το όριο των 10 MB. Αφαίρεσε κάποιες σελίδες ή διάλεξε «Ασπρόμαυρο».'));
            return;
        }
        try {
            const transfer = new DataTransfer();
            transfer.items.add(file);
            docInput.files = transfer.files;
            docInput.dispatchEvent(new Event('change', { bubbles: true }));
            ready.hidden = false;
            ready.textContent = _('✓ Έτοιμο: ') + file.name + ' (' + pages.length + (pages.length === 1 ? _(' σελίδα, ') : _(' σελίδες, ')) + size + _('). Συμπλήρωσε τα υπόλοιπα και πάτα «Ανέβασμα».');
            close();
            const title = document.querySelector('input[name="title"]');
            if (title) { title.focus(); }
        } catch (e) {
            // Παλιός browser: κατεβάζουμε το PDF για να το επιλέξει ο χρήστης μόνος του
            const link = document.createElement('a');
            link.href = URL.createObjectURL(pdf);
            link.download = file.name;
            link.click();
            say(_('Το PDF κατέβηκε στη συσκευή σου. Επίλεξέ το στο πεδίο «Αρχείο».'));
        }
    }

    // ---------- Κουμπιά ----------
    document.getElementById('scan-open').addEventListener('click', open);
    ['scan-camera', 'scan-gallery'].forEach(function (id) {
        document.getElementById(id).addEventListener('change', async function () {
            const files = Array.from(this.files);
            this.value = '';
            if (!files.length) { return; }
            if (files.length === 1) {
                await loadFile(files[0]);
                return;
            }
            // Πολλές εικόνες από τη συλλογή: τις προσθέτουμε όλες χωρίς περικοπή (μπορείς να τις διορθώσεις μία-μία)
            for (const file of files) {
                await loadFile(file);
                await savePage();
            }
        });
    });
    panel.addEventListener('click', function (event) {
        const action = event.target.closest('[data-scan]');
        if (!action) { return; }
        const name = action.dataset.scan;
        if (name === 'close') { close(); }
        if (name === 'rotate') { rotate(); }
        if (name === 'reset') { resetCorners(); draw(); }
        if (name === 'cancel-edit') { source = null; showStep('pages'); }
        if (name === 'save-page') { savePage(); }
    });
    makeButton.addEventListener('click', makePdf);

    if (new URLSearchParams(location.search).get('scan') === '1') { open(); }
    renderPages();
})();
