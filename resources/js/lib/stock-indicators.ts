export type StockIndicatorTone = 'neutral' | 'low' | 'empty' | 'reminder';

export type StockIndicator = {
    label: string;
    tone: StockIndicatorTone;
};

export type StockIndicatorLevel = {
    quantity: number;
    alert_threshold: number | null;
    alert_status: string;
    location?: { name: string };
};

export type StockIndicatorAlert = {
    alert_type: string;
    resolved_at: string | null;
};

export function getStockLevelIndicators(level: StockIndicatorLevel): StockIndicator[] {
    const isUnmonitored = level.alert_status === 'unmonitored' || level.alert_threshold === null;

    if (level.quantity <= 0) {
        return [
            { label: 'Empty', tone: isUnmonitored ? 'neutral' : 'empty' },
            isUnmonitored ? { label: 'Unmonitored', tone: 'neutral' } : { label: 'Monitored', tone: 'neutral' },
        ];
    }

    if (level.alert_status === 'low') {
        return [{ label: 'Low stock', tone: 'low' }];
    }

    if (isUnmonitored) {
        return [{ label: 'Unmonitored', tone: 'neutral' }];
    }

    return [{ label: 'In stock', tone: 'neutral' }];
}

export function getMonitoredAttentionLevels<T extends StockIndicatorLevel>(levels: T[]): Array<{ level: T; indicator: StockIndicator }> {
    const indicators: Array<{ level: T; indicator: StockIndicator }> = [];

    for (const level of levels) {
        if (level.alert_threshold !== null && level.alert_status === 'low') {
            indicators.push({ level, indicator: { label: 'Low stock', tone: 'low' } });
            continue;
        }

        if (level.alert_threshold !== null && level.alert_status === 'empty') {
            indicators.push({ level, indicator: { label: 'Out of stock', tone: 'empty' } });
        }
    }

    return indicators;
}

export function hasActiveBuySoonAlert(alerts: StockIndicatorAlert[] | undefined): boolean {
    return alerts?.some((alert) => alert.alert_type === 'buy_soon' && alert.resolved_at === null) ?? false;
}
