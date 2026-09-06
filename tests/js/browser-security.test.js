import assert from 'node:assert/strict';
import test from 'node:test';

import {
    createCardContent,
    handleVehicleStatusChanged,
    updateCardStatus,
} from '../../resources/js/modules/active-jeeps.js';
import { parseVehicleResponse } from '../../resources/js/modules/tracking.js';

class FakeNode {
    constructor(tagName = '') {
        this.tagName = tagName;
        this.children = [];
        this.className = '';
        this.dataset = {};
        this.style = {};
        this.textContent = '';
        this.removed = false;
        this.classList = {
            add: () => {},
            remove: () => {},
        };
    }

    appendChild(child) {
        this.children.push(child);
        return child;
    }

    append(...children) {
        this.children.push(...children);
    }

    remove() {
        this.removed = true;
    }
}

function installDocument(querySelector = () => null) {
    globalThis.document = {
        createElement: tagName => new FakeNode(tagName),
        createDocumentFragment: () => new FakeNode('#fragment'),
        querySelector,
    };
}

test.afterEach(() => {
    delete globalThis.document;
    delete globalThis.requestAnimationFrame;
});

test('renders hostile route and operator values as literal text nodes', () => {
    installDocument();
    const route = '<img src=x onerror=alert(1)>';
    const operator = '<svg onload=alert(2)>';

    const fragment = createCardContent(route, operator, false, 'moving', null);

    assert.equal(fragment.children[0].textContent, `Route: ${route}`);
    assert.equal(fragment.children[1].textContent, `Operator: ${operator}`);
    assert.equal(fragment.children[0].children.length, 0);
    assert.equal(fragment.children[1].children.length, 0);
});

test('applies a realtime update only to the matching vehicle card', () => {
    const routeElement = new FakeNode('p');
    const statusText = new FakeNode('p');
    const statusBadge = new FakeNode('div');
    statusBadge.querySelector = selector => selector === '.statusText' ? statusText : null;
    const occupancyText = new FakeNode('p');
    const occupancyBadge = new FakeNode('div');
    occupancyBadge.querySelector = selector => selector === 'p' ? occupancyText : null;
    const card = new FakeNode('a');
    card.querySelector = selector => ({
        '.jeepRoute': routeElement,
        '.statusBadge': statusBadge,
        '.occupancyBadge': occupancyBadge,
    })[selector] ?? null;
    const selectors = [];

    installDocument(selector => {
        selectors.push(selector);
        return selector === '[data-vehicle-id="7"]' ? card : null;
    });

    updateCardStatus(7, 'moving', '2026-01-01T00:00:00Z', 3, true, 'Safe Route');

    assert.deepEqual(selectors, ['[data-vehicle-id="7"]']);
    assert.equal(routeElement.textContent, 'Route: Safe Route');
    assert.equal(statusText.textContent, '● LIVE');
    assert.equal(occupancyText.textContent, 'FULL');
});

test('removes the matching card when realtime state reports an ended shift', () => {
    const card = new FakeNode('a');
    installDocument(selector => selector === '[data-vehicle-id="12"]' ? card : null);
    globalThis.requestAnimationFrame = callback => callback();

    handleVehicleStatusChanged(new FakeNode('div'), {
        id: 12,
        gps_status: 'shift_ended',
    });

    assert.equal(card.removed, false);
    return new Promise(resolve => {
        setTimeout(() => {
            assert.equal(card.removed, true);
            resolve();
        }, 325);
    });
});

test('redirects to login when the tracking session has expired', async () => {
    const redirects = [];
    const result = await parseVehicleResponse(
        { status: 401, ok: false },
        path => redirects.push(path),
    );

    assert.equal(result, null);
    assert.deepEqual(redirects, ['/']);
});

test('returns successful tracking JSON without redirecting', async () => {
    const payload = { id: 4, gps_status: 'moving' };
    const redirects = [];
    const result = await parseVehicleResponse(
        { status: 200, ok: true, json: async () => payload },
        path => redirects.push(path),
    );

    assert.deepEqual(result, payload);
    assert.deepEqual(redirects, []);
});
