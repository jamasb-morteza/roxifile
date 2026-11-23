import test from 'node:test';
import assert from 'node:assert/strict';
import {
    createUploadTracker,
    uploadRetryMode,
} from '../../resources/js/lib/uploadTracker.ts';

void test('early events and results only affect the corresponding operation', () => {
    const tracker = createUploadTracker();
    const cards = [
        { status: 'uploading', progress: 90 },
        { status: 'uploading', progress: 0 },
    ];
    tracker.record(
        { operation_id: 11, status: 'completed', progress: 100 },
        cards,
    );
    tracker.attach(cards[0], 11);
    tracker.attach(cards[1], 12);
    assert.equal(cards[0].status, 'done');
    assert.equal(cards[1].status, 'processing');
    tracker.record({ operation_id: 12, status: 'failed', progress: 20 }, cards);
    assert.equal(cards[1].status, 'failed');
    assert.equal(cards[0].status, 'done');
    tracker.record(
        { operation_id: 11, status: 'uploading', progress: 30 },
        cards,
    );
    assert.equal(cards[0].status, 'done');
    assert.equal(cards[0].progress, 100);
});

void test('failed uploads receive the server retry-source decision', () => {
    const tracker = createUploadTracker();
    const cards = [{ operationId: 22, status: 'processing', progress: 20 }];

    tracker.record(
        {
            operation_id: 22,
            status: 'failed',
            progress: 20,
            can_retry_operation: false,
        },
        cards,
    );

    assert.equal(cards[0].canRetryOperation, false);
    assert.equal(uploadRetryMode(cards[0]), 'upload');
    assert.equal(
        uploadRetryMode({ ...cards[0], needsReview: true }),
        'blocked',
    );

    const reviewCard = {
        operationId: 23,
        status: 'processing',
        progress: 95,
    };
    tracker.record({ operation_id: 23, status: 'needs_review', progress: 95 }, [
        reviewCard,
    ]);

    assert.equal(reviewCard.needsReview, true);
    assert.equal(uploadRetryMode(reviewCard), 'blocked');
});
