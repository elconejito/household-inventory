import { describe, expect, it } from 'vitest';
import { getMonitoredAttentionLevels, getStockLevelIndicators, hasActiveBuySoonAlert } from './stock-indicators';

describe('stock indicators', () => {
    it('reports factual emptiness separately from monitoring', () => {
        expect(getStockLevelIndicators({ quantity: 0, alert_threshold: null, alert_status: 'unmonitored' })).toEqual([
            { label: 'Empty', tone: 'neutral' },
            { label: 'Unmonitored', tone: 'neutral' },
        ]);
        expect(getStockLevelIndicators({ quantity: 0, alert_threshold: 0, alert_status: 'empty' })).toEqual([
            { label: 'Empty', tone: 'empty' },
            { label: 'Monitored', tone: 'neutral' },
        ]);
        expect(getStockLevelIndicators({ quantity: 1, alert_threshold: 2, alert_status: 'low' })).toEqual([{ label: 'Low stock', tone: 'low' }]);
        expect(getStockLevelIndicators({ quantity: 3, alert_threshold: 2, alert_status: 'okay' })).toEqual([{ label: 'In stock', tone: 'neutral' }]);
    });

    it('only promotes explicit monitored low and empty alert statuses to inventory attention', () => {
        const levels = [
            { quantity: 0, alert_threshold: null, alert_status: 'unmonitored', location: { name: 'Bathroom' } },
            { quantity: 0, alert_threshold: null, alert_status: 'low', location: { name: 'Unmonitored stale low' } },
            { quantity: 2, alert_threshold: 3, alert_status: 'low', location: { name: 'Pantry' } },
            { quantity: 0, alert_threshold: 0, alert_status: 'empty', location: { name: 'Closet' } },
            { quantity: 8, alert_threshold: 2, alert_status: 'okay', location: { name: 'Garage' } },
        ];

        expect(getMonitoredAttentionLevels(levels)).toEqual([
            { level: levels[2], indicator: { label: 'Low stock', tone: 'low' } },
            { level: levels[3], indicator: { label: 'Out of stock', tone: 'empty' } },
        ]);
    });

    it('detects active Buy soon alerts independently from stock level status', () => {
        expect(hasActiveBuySoonAlert([{ alert_type: 'buy_soon', resolved_at: null }])).toBe(true);
        expect(hasActiveBuySoonAlert([{ alert_type: 'buy_soon', resolved_at: '2026-01-01T00:00:00Z' }])).toBe(false);
        expect(hasActiveBuySoonAlert([{ alert_type: 'other', resolved_at: null }])).toBe(false);
        expect(hasActiveBuySoonAlert(undefined)).toBe(false);
    });
});
