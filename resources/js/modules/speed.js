export const METERS_PER_SECOND_TO_KILOMETERS_PER_HOUR = 3.6;
export const DEFAULT_MOVING_THRESHOLD_MPS = 3 / METERS_PER_SECOND_TO_KILOMETERS_PER_HOUR;
export const DEFAULT_TRAFFIC_THRESHOLD_MPS = 0.5 / METERS_PER_SECOND_TO_KILOMETERS_PER_HOUR;

export function normalizeSpeedMps(value) {
    if (value === null || value === undefined
        || (typeof value === 'string' && value.trim() === '')) {
        return null;
    }

    const speedMps = Number(value);

    return Number.isFinite(speedMps) && speedMps >= 0 ? speedMps : null;
}

export function speedMpsToKph(value) {
    const speedMps = normalizeSpeedMps(value);

    return speedMps === null
        ? null
        : speedMps * METERS_PER_SECOND_TO_KILOMETERS_PER_HOUR;
}

export function formatSpeedMps(value) {
    const speedKph = speedMpsToKph(value);

    return speedKph === null ? '-- km/h' : `${Math.round(speedKph)} km/h`;
}

export function deriveProvisionalGpsStatus(
    value,
    movingThresholdMps = DEFAULT_MOVING_THRESHOLD_MPS,
    trafficThresholdMps = DEFAULT_TRAFFIC_THRESHOLD_MPS,
) {
    const speedMps = normalizeSpeedMps(value) ?? 0;

    if (speedMps >= movingThresholdMps) return 'moving';
    if (speedMps >= trafficThresholdMps) return 'traffic';

    return 'idle';
}
