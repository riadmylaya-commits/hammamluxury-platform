/* Run without installing packages: node --test tests/Browser/public-content-security.test.cjs */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const project = path.resolve(__dirname, '../..');

class Element {
    constructor(tagName = 'img') {
        this.tagName = tagName.toUpperCase();
        this.children = [];
        this.attributes = {};
        this.dataset = {};
        this.listeners = {};
        this.className = '';
        this.classList = { add() {}, remove() {}, toggle() {}, contains: (name) => this.className.split(' ').includes(name) };
    }
    set innerHTML(value) { throw new Error('Untrusted HTML parsing is forbidden in these renderers.'); }
    appendChild(child) { this.children.push(child); return child; }
    setAttribute(key, value) { this.attributes[key] = value; }
    removeAttribute(key) { delete this[key]; delete this.attributes[key]; }
    addEventListener(key, callback) { this.listeners[key] = callback; }
    focus() {}
    scrollIntoView() {}
    querySelector(selector) {
        for (const child of this.children) {
            if (selector.startsWith('.') ? child.className.split(' ').includes(selector.slice(1)) : child.tagName.toLowerCase() === selector) return child;
            const found = child.querySelector(selector);
            if (found) return found;
        }
        return null;
    }
}

function gallery(photos, i18n = {}, hash = '#photo-1') {
    const root = new Element('div');
    root.dataset.photos = typeof photos === 'string' ? photos : JSON.stringify(photos);
    root.dataset.i18n = JSON.stringify(i18n);
    const body = new Element('body');
    const document = {
        body, readyState: 'complete', activeElement: null,
        getElementById: () => root, createElement: (tag) => new Element(tag), addEventListener() {},
    };
    vm.runInNewContext(fs.readFileSync(path.join(project, 'public/js/hl-gallery.js'), 'utf8'), {
        document, URL, Image: Element, history: { replaceState() {} }, location: { hash, pathname: '/fr/spa/test', search: '' },
    });
    return { root, box: body.children[0] };
}

test('gallery creates image nodes and keeps captions and translated labels as text', () => {
    const caption = '<img src=x onerror=alert(1)>';
    const { box } = gallery([{ url: 'https://images.example.test/photo.jpg', caption }], { close: '" onclick="alert(1)' });
    assert.equal(box.querySelector('.lb-stage').querySelector('img').src, 'https://images.example.test/photo.jpg');
    assert.equal(box.querySelector('.lb-cap').textContent, caption);
    assert.equal(box.querySelector('.lb-close').attributes['aria-label'], '" onclick="alert(1)');
    const thumbnail = box.querySelector('.lb-thumbs').children[0].children[0];
    assert.equal(thumbnail.tagName, 'IMG');
    assert.equal(thumbnail.src, 'https://images.example.test/photo.jpg');
    assert.equal(thumbnail.loading, 'lazy');
});

test('gallery refuses dangerous URL schemes, quoted URLs, credentials and traversal', () => {
    for (const url of [
        'javascript:alert(1)', 'data:image/svg+xml,<svg onload=alert(1)>', '//evil.test/a.jpg',
        'https://images.test/a.jpg" onerror="alert(1)', 'https://images.test/a.jpg\n',
        'https://user:password@images.test/a.jpg', '/storage/../secret.jpg', '/\\evil.test/a.jpg',
    ]) {
        const { box } = gallery([{ url, caption: 'test' }]);
        assert.equal(box.querySelector('.lb-stage').querySelector('img').src, undefined, url);
        assert.equal(box.querySelector('.lb-thumbs').children[0].children[0].src, undefined, url);
    }
});

test('gallery keeps safe local and HTTP(S) images working', () => {
    for (const url of ['/storage/spas/1/photo.jpg', 'storage/spas/photo.jpg', 'https://images.test/a.jpg?w=800&h=600', 'http://images.test/a.jpg']) {
        const { box } = gallery([{ url }]);
        assert.equal(box.querySelector('.lb-stage').querySelector('img').src, url);
    }
});

test('gallery ignores malformed photo data without rendering HTML', () => {
    assert.equal(gallery('{invalid').box, undefined);
    assert.equal(gallery({ unexpected: true }).box, undefined);
});

test('Leaflet popup receives a text DOM node for a malicious establishment name', () => {
    const source = fs.readFileSync(path.join(project, 'resources/views/components/layouts/site.blade.php'), 'utf8');
    const script = source.match(/<script>\s*([\s\S]*?)<\/script>/)[1];
    const map = new Element('div');
    map.dataset = { lat: '31.6', lng: '-7.9', title: '<img src=x onerror=alert(1)>' };
    const head = new Element('head');
    let popup;
    const document = { head, getElementById: () => map, createElement: (tag) => new Element(tag) };
    const L = {
        map: () => ({ setView() { return this; } }), tileLayer: () => ({ addTo() {} }),
        marker: () => ({ addTo() { return this; }, bindPopup(value) { popup = value; } }),
    };
    vm.runInNewContext(script, { document, L });
    head.children.find((node) => node.tagName === 'SCRIPT').onload();
    assert.equal(popup.tagName, 'SPAN');
    assert.equal(popup.textContent, map.dataset.title);
    assert.equal(popup.children.length, 0);
});
