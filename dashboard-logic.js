(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.ObservatoryDashboardLogic = api;
    }
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const DAY_MS = 24 * 60 * 60 * 1000;

    function finiteTimestamp(value) {
        return typeof value === 'number' && Number.isFinite(value);
    }

    function ageMilliseconds(lastSeen, now) {
        if (!finiteTimestamp(lastSeen)) return null;
        const currentTime = finiteTimestamp(now) ? now : Date.now();
        return Math.max(0, currentTime - lastSeen);
    }

    function freshnessState(lastSeen, now, staleAfterMs) {
        const age = ageMilliseconds(lastSeen, now);
        if (age === null) return 'missing';
        return age >= staleAfterMs ? 'stale' : 'fresh';
    }

    function formatAge(lastSeen, now) {
        const age = ageMilliseconds(lastSeen, now);
        if (age === null) return '--';
        const seconds = Math.floor(age / 1000);
        if (seconds < 60) return `${seconds}s`;
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) return `${minutes}m`;
        const hours = Math.floor(minutes / 60);
        return `${hours}h`;
    }

    function classifyDewRisk(temperature, dewPoint) {
        if (!Number.isFinite(temperature) || !Number.isFinite(dewPoint)) return null;
        const margin = temperature - dewPoint;
        if (margin <= 2) return { margin, state: 'critical', label: 'Critical' };
        if (margin <= 4) return { margin, state: 'watch', label: 'Watch' };
        return { margin, state: 'clear', label: 'Clear' };
    }

    function observingState(options) {
        const connectionState = options.connectionState;
        if (connectionState === 'connecting') {
            return { state: 'assessing', label: 'Assessing', reason: 'connecting' };
        }
        if (connectionState === 'disconnected' || connectionState === 'reconnecting') {
            return { state: 'unknown', label: 'Status unknown', reason: 'disconnected' };
        }
        if (connectionState === 'unavailable') {
            return { state: 'unknown', label: 'Status unknown', reason: 'unavailable' };
        }
        if (!options.safetyReceived) {
            return { state: 'assessing', label: 'Assessing', reason: 'awaiting' };
        }
        if (freshnessState(options.safetyLastSeen, options.now, options.staleAfterMs) !== 'fresh') {
            return { state: 'unknown', label: 'Status unknown', reason: 'stale' };
        }
        if (options.safetyState === 'favorable') {
            return { state: 'safe', label: 'Safe to observe', reason: 'permitted' };
        }
        if (options.safetyState === 'warning') {
            return { state: 'unsafe', label: 'Unsafe to observe', reason: 'blocked' };
        }
        return { state: 'unknown', label: 'Status unknown', reason: 'invalid' };
    }

    function validDate(value) {
        return value instanceof Date && Number.isFinite(value.getTime());
    }

    function observingNight(now, sunCalc, latitude, longitude) {
        if (!validDate(now) || !sunCalc || typeof sunCalc.getTimes !== 'function') return null;

        const currentTimes = sunCalc.getTimes(now, latitude, longitude);
        if (!currentTimes || !validDate(currentTimes.nightEnd)) return null;

        let start;
        let end;
        if (now < currentTimes.nightEnd) {
            const previousTimes = sunCalc.getTimes(new Date(now.getTime() - DAY_MS), latitude, longitude);
            start = previousTimes && previousTimes.night;
            end = currentTimes.nightEnd;
        } else {
            const nextTimes = sunCalc.getTimes(new Date(now.getTime() + DAY_MS), latitude, longitude);
            start = currentTimes.night;
            end = nextTimes && nextTimes.nightEnd;
        }

        if (!validDate(start) || !validDate(end) || start >= end) return null;
        return { start, end, midpoint: new Date((start.getTime() + end.getTime()) / 2) };
    }

    function moonIlluminationPercent(sunCalc, date) {
        if (!sunCalc || typeof sunCalc.getMoonIllumination !== 'function' || !validDate(date)) return null;
        const illumination = sunCalc.getMoonIllumination(date);
        if (!illumination || !Number.isFinite(illumination.fraction)) return null;
        return Math.round(Math.min(1, Math.max(0, illumination.fraction)) * 100);
    }

    function timeZoneName(date, timeZone) {
        const parts = new Intl.DateTimeFormat('en-GB', {
            timeZone,
            timeZoneName: 'short'
        }).formatToParts(date);
        return parts.find(part => part.type === 'timeZoneName')?.value || '';
    }

    function formatTime(date, timeZone) {
        return new Intl.DateTimeFormat('en-GB', {
            timeZone,
            hour: '2-digit',
            minute: '2-digit',
            hour12: false
        }).format(date);
    }

    function formatTimeRange(start, end, timeZone) {
        if (!validDate(start) || !validDate(end)) return null;
        const startTime = formatTime(start, timeZone);
        const endTime = formatTime(end, timeZone);
        const startZone = timeZoneName(start, timeZone);
        const endZone = timeZoneName(end, timeZone);
        if (startZone === endZone) return `${startTime}–${endTime} ${endZone}`.trim();
        return `${startTime} ${startZone}–${endTime} ${endZone}`.trim();
    }

    return {
        ageMilliseconds,
        freshnessState,
        formatAge,
        classifyDewRisk,
        observingState,
        observingNight,
        moonIlluminationPercent,
        formatTimeRange
    };
}));
