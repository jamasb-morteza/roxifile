import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    canClearTransfer,
    visibleTransfers,
} from '../../resources/js/lib/transferHistory.ts';

void test('clearing history preserves active and review operations including retried entries', () => {
    const transfers = [
        'completed',
        'failed',
        'cancelled',
        'pending',
        'processing',
        'uploading',
        'downloading',
        'retrying',
        'needs_review',
    ].map((status, operation_id) => ({ status, operation_id }));
    const dismissed = transfers
        .filter(canClearTransfer)
        .map((item) => item.operation_id);
    assert.deepEqual(
        visibleTransfers(transfers, dismissed).map((item) => item.status),
        [
            'pending',
            'processing',
            'uploading',
            'downloading',
            'retrying',
            'needs_review',
        ],
    );
    assert.equal(
        visibleTransfers([{ operation_id: 1, status: 'retrying' }], dismissed)
            .length,
        1,
    );
    assert.equal(
        visibleTransfers([{ operation_id: 20, status: 'completed' }], dismissed)
            .length,
        1,
    );
});
