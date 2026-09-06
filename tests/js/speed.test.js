import test from 'node:test';
import assert from 'node:assert/strict';

import {
    DEFAULT_MOVING_THRESHOLD_MPS,
    DEFAULT_TRAFFIC_THRESHOLD_MPS,
    deriveProvisionalGpsStatus,
    formatSpeedMps,
    speedMpsToKph,
} from '../../resources/js/modules/speed.js';

test('converts meters per second to kilometers per hour for display', () => {
    assert.equal(speedMpsToKph(0), 0);
    assert.equal(speedMpsToKph(1), 3.6);
    assert.equal(speedMpsToKph(3), 10.8);
    assert.equal(formatSpeedMps(0), '0 km/h');
    assert.equal(formatSpeedMps(1), '4 km/h');
    assert.equal(formatSpeedMps(3), '11 km/h');
});

test('rejects invalid speed values for presentation', () => {
    assert.equal(speedMpsToKph(null), null);
    assert.equal(speedMpsToKph(-1), null);
    assert.equal(speedMpsToKph('not-a-number'), null);
    assert.equal(formatSpeedMps(null), '-- km/h');
});

test('uses the same m/s movement boundary as the server contract', () => {
    assert.equal(
        deriveProvisionalGpsStatus(DEFAULT_TRAFFIC_THRESHOLD_MPS - 0.001),
        'idle',
    );
    assert.equal(
        deriveProvisionalGpsStatus(DEFAULT_TRAFFIC_THRESHOLD_MPS),
        'traffic',
    );
    assert.equal(
        deriveProvisionalGpsStatus(DEFAULT_MOVING_THRESHOLD_MPS - 0.001),
        'traffic',
    );
    assert.equal(
        deriveProvisionalGpsStatus(DEFAULT_MOVING_THRESHOLD_MPS),
        'moving',
    );
    assert.equal(
        deriveProvisionalGpsStatus(DEFAULT_MOVING_THRESHOLD_MPS + 0.001),
        'moving',
    );
});
