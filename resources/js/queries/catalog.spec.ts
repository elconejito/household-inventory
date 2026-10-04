import { describe, expect, it } from 'vitest';
import { catalogQueryKeys } from './catalog';

describe('catalog query invalidation namespaces', () => {
    it('shares existing item and inventory-level cache roots', () => {
        expect(catalogQueryKeys.categoryItems('8', { search: '', page: 1, perPage: 10 })[0]).toBe('items');
        expect(catalogQueryKeys.locationLevels(['3'], { search: '', page: 1, perPage: 10 })[0]).toBe('inventory-levels');
    });
});
