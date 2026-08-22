import test from 'node:test';
import assert from 'node:assert/strict';

import { escapeHtml } from '../../resources/js/modules/dom.js';

test('escapeHtml renders an XSS payload as literal text', () => {
    assert.equal(
        escapeHtml(`<img src=x onerror="alert('xss')">`),
        '&lt;img src=x onerror=&quot;alert(&#039;xss&#039;)&quot;&gt;',
    );
});

test('escapeHtml handles nullish values consistently', () => {
    assert.equal(escapeHtml(null), '');
    assert.equal(escapeHtml(undefined), '');
});
