/**
 * Multi-timeframe upload workspace controller (Phase 3.2). One instance per
 * `.vw-slot` element (drag&drop, browse, replace, remove, preview, zoom),
 * plus one page-wide paste listener that routes Ctrl+V to whichever slot
 * was last focused/clicked — the natural UX for multiple dropzones sharing
 * one clipboard. Replaces the single-dropzone inline script this page used
 * to have (hardcoded to exactly one input/preview pair, not reusable as-is
 * across 9 slots). Zero external dependencies — plain DOM APIs only.
 */
(function () {
    'use strict';

    var activeSlot = null;

    function showPreview(slot, file) {
        if (!file || file.type.indexOf('image/') !== 0) { return; }
        var url = URL.createObjectURL(file);
        slot.preview.src = url;
        slot.el.classList.add('is-filled');
        slot.filenameEl.textContent = file.name || 'Pasted image';
    }

    function clearSlot(slot) {
        slot.input.value = '';
        slot.preview.src = '';
        slot.el.classList.remove('is-filled');
        slot.filenameEl.textContent = '';
    }

    function assignFiles(slot, files) {
        if (!files || !files.length) { return; }
        var dt = new DataTransfer();
        dt.items.add(files[0]);
        slot.input.files = dt.files;
        showPreview(slot, files[0]);
    }

    function openZoom(src) {
        var modal = document.getElementById('vw-zoom-modal');
        var img   = document.getElementById('vw-zoom-image');
        if (!modal || !img || !src) { return; }
        img.src = src;
        modal.classList.add('is-open');
    }

    function closeZoom() {
        var modal = document.getElementById('vw-zoom-modal');
        if (modal) { modal.classList.remove('is-open'); }
    }

    function initSlot(el) {
        var slot = {
            el: el,
            input: el.querySelector('[data-role="input"]'),
            preview: el.querySelector('[data-role="preview"]'),
            filenameEl: el.querySelector('[data-role="filename"]'),
            removeBtn: el.querySelector('[data-role="remove"]'),
            zoomBtn: el.querySelector('[data-role="zoom"]'),
        };
        if (!slot.input || !slot.preview) { return; }

        slot.input.addEventListener('change', function () {
            if (slot.input.files && slot.input.files[0]) { showPreview(slot, slot.input.files[0]); }
        });

        el.addEventListener('click', function () { activeSlot = slot; });
        el.addEventListener('focusin', function () { activeSlot = slot; });

        ['dragover', 'dragleave', 'drop'].forEach(function (evt) {
            el.addEventListener(evt, function (e) {
                e.preventDefault();
                el.classList.toggle('is-dragover', evt === 'dragover');
                if (evt === 'drop' && e.dataTransfer && e.dataTransfer.files.length) {
                    assignFiles(slot, e.dataTransfer.files);
                }
            });
        });

        if (slot.removeBtn) {
            slot.removeBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                clearSlot(slot);
            });
        }
        if (slot.zoomBtn) {
            slot.zoomBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                openZoom(slot.preview.src);
            });
        }

        return slot;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var slots = [];
        document.querySelectorAll('.vw-slot').forEach(function (el) {
            var slot = initSlot(el);
            if (slot) { slots.push(slot); }
        });

        // Paste routes to whichever slot was last clicked/focused, defaulting
        // to the first empty slot if nothing has been focused yet.
        document.addEventListener('paste', function (e) {
            var items = (e.clipboardData || window.clipboardData || {}).items;
            if (!items) { return; }

            var target = activeSlot;
            if (!target) {
                for (var i = 0; i < slots.length; i++) {
                    if (!slots[i].input.files || !slots[i].input.files.length) { target = slots[i]; break; }
                }
            }
            if (!target) { return; }

            for (var j = 0; j < items.length; j++) {
                if (items[j].type.indexOf('image/') === 0) {
                    var file = items[j].getAsFile();
                    if (!file) { continue; }
                    var dt = new DataTransfer();
                    dt.items.add(file);
                    target.input.files = dt.files;
                    showPreview(target, file);
                    e.preventDefault();
                    break;
                }
            }
        });

        var zoomModal = document.getElementById('vw-zoom-modal');
        if (zoomModal) {
            zoomModal.addEventListener('click', function (e) {
                if (e.target === zoomModal || e.target.getAttribute('data-role') === 'zoom-close') {
                    closeZoom();
                }
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeZoom(); }
        });
    });
}());
