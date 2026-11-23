export type UploadState = {
    operationId?: number;
    canRetryOperation?: boolean;
    needsReview?: boolean;
    status: 'uploading' | 'processing' | 'done' | 'failed';
    progress: number;
    error?: string;
};

export type OperationSnapshot = {
    operation_id: number;
    status: string;
    progress: number;
    can_retry_operation?: boolean;
};

export type UploadRetryMode = 'operation' | 'upload' | 'blocked';

export function uploadRetryMode(
    card: Pick<
        UploadState,
        'operationId' | 'canRetryOperation' | 'needsReview'
    >,
): UploadRetryMode {
    if (card.needsReview) return 'blocked';
    if (card.operationId !== undefined && card.canRetryOperation !== false) {
        return 'operation';
    }

    return 'upload';
}

export function createUploadTracker() {
    const early = new Map<number, OperationSnapshot>();

    function apply(card: UploadState, event: OperationSnapshot): void {
        if (event.can_retry_operation !== undefined) {
            card.canRetryOperation = event.can_retry_operation;
        }
        card.needsReview = event.status === 'needs_review';
        if (card.status === 'done' || card.status === 'failed') return;
        card.progress = Math.max(
            card.progress,
            Math.min(100, Math.max(0, event.progress)),
        );
        if (event.status === 'completed') {
            card.status = 'done';
            card.progress = 100;
        } else if (
            ['failed', 'cancelled', 'needs_review'].includes(event.status)
        ) {
            card.status = 'failed';
            card.error =
                event.status === 'needs_review'
                    ? 'The upload needs review before retrying.'
                    : 'The Telegram transfer did not complete.';
        }
    }

    return {
        attach(card: UploadState, operationId: number): void {
            card.operationId = operationId;
            card.canRetryOperation = undefined;
            card.needsReview = false;
            card.status = 'processing';
            card.progress = 0;
            const event = early.get(operationId);
            if (event) apply(card, event);
            early.delete(operationId);
        },
        record(event: OperationSnapshot, cards: UploadState[]): void {
            const card = cards.find(
                (item) => item.operationId === event.operation_id,
            );
            if (card) {
                apply(card, event);
                return;
            }
            const previous = early.get(event.operation_id);
            if (
                previous &&
                ['completed', 'failed', 'cancelled', 'needs_review'].includes(
                    previous.status,
                )
            )
                return;
            early.set(event.operation_id, event);
            if (early.size > 200) early.delete(early.keys().next().value!);
        },
    };
}
