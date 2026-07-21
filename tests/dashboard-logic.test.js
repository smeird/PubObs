const test = require('node:test');
const assert = require('node:assert/strict');

const logic = require('../dashboard-logic.js');
const SunCalc = require('../vendor/suncalc-1.9.0.js');

test('freshness changes at the exact threshold and recovers with a new timestamp', () => {
    const now = Date.UTC(2026, 6, 21, 18, 0, 0);
    assert.equal(logic.freshnessState(null, now, 150000), 'missing');
    assert.equal(logic.freshnessState(now - 149999, now, 150000), 'fresh');
    assert.equal(logic.freshnessState(now - 150000, now, 150000), 'stale');
    assert.equal(logic.freshnessState(now - 1000, now, 150000), 'fresh');
    assert.equal(logic.freshnessState(now - 299999, now, 300000), 'fresh');
    assert.equal(logic.freshnessState(now - 300000, now, 300000), 'stale');
});

test('age labels stay compact', () => {
    const now = Date.UTC(2026, 6, 21, 18, 0, 0);
    assert.equal(logic.formatAge(null, now), '--');
    assert.equal(logic.formatAge(now - 18000, now), '18s');
    assert.equal(logic.formatAge(now - 119000, now), '1m');
    assert.equal(logic.formatAge(now - 2 * 60 * 60 * 1000, now), '2h');
});

test('dew risk honours the two and four degree boundaries', () => {
    assert.equal(logic.classifyDewRisk(10, 8).state, 'critical');
    assert.equal(logic.classifyDewRisk(10.01, 8).state, 'watch');
    assert.equal(logic.classifyDewRisk(12, 8).state, 'watch');
    assert.equal(logic.classifyDewRisk(12.01, 8).state, 'clear');
    assert.equal(logic.classifyDewRisk(undefined, 8), null);
});

test('observing state fails closed and recovers only with current safety data', () => {
    const now = Date.UTC(2026, 6, 21, 18, 0, 0);
    const base = {
        connectionState: 'connected',
        safetyReceived: true,
        safetyState: 'favorable',
        safetyLastSeen: now,
        now,
        staleAfterMs: 150000
    };

    assert.equal(logic.observingState(base).state, 'safe');
    assert.equal(logic.observingState({ ...base, safetyReceived: false }).state, 'assessing');
    assert.equal(logic.observingState({ ...base, safetyLastSeen: now - 150000 }).state, 'unknown');
    assert.equal(logic.observingState({ ...base, connectionState: 'disconnected' }).state, 'unknown');
    assert.equal(logic.observingState({ ...base, connectionState: 'unavailable' }).state, 'unknown');
    assert.equal(logic.observingState({ ...base, safetyState: 'warning' }).state, 'unsafe');
    assert.equal(logic.observingState({ ...base, safetyLastSeen: now - 1000 }).state, 'safe');
});

test('observing night selects the upcoming window during the day', () => {
    const now = new Date('2026-01-15T12:00:00Z');
    const night = logic.observingNight(now, SunCalc, 51.81, -0.29);
    assert.ok(night);
    assert.ok(night.start > now);
    assert.ok(night.end > night.start);
    assert.ok(night.midpoint > night.start && night.midpoint < night.end);
});

test('observing night selects the current window before dawn', () => {
    const now = new Date('2026-01-15T02:00:00Z');
    const night = logic.observingNight(now, SunCalc, 51.81, -0.29);
    assert.ok(night);
    assert.ok(night.start < now);
    assert.ok(night.end > now);
});

test('summer without full astronomical darkness returns a clear fallback', () => {
    const now = new Date('2026-06-21T12:00:00Z');
    assert.equal(logic.observingNight(now, SunCalc, 51.81, -0.29), null);
});

test('moon illumination is a whole percentage in range', () => {
    const illumination = logic.moonIlluminationPercent(SunCalc, new Date('2026-01-15T00:00:00Z'));
    assert.ok(Number.isInteger(illumination));
    assert.ok(illumination >= 0 && illumination <= 100);
});

test('London time ranges identify ordinary and DST-transition nights', () => {
    assert.equal(
        logic.formatTimeRange(new Date('2026-01-15T20:00:00Z'), new Date('2026-01-16T05:00:00Z'), 'Europe/London'),
        '20:00–05:00 GMT'
    );
    assert.equal(
        logic.formatTimeRange(new Date('2026-03-28T22:00:00Z'), new Date('2026-03-29T04:00:00Z'), 'Europe/London'),
        '22:00 GMT–05:00 BST'
    );
});
