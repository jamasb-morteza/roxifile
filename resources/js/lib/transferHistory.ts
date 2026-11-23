type Transfer = { operation_id: number; status: string };

export function canClearTransfer(transfer: Transfer): boolean {
    return ['completed', 'failed', 'cancelled'].includes(transfer.status);
}

export function visibleTransfers<T extends Transfer>(
    transfers: T[],
    dismissed: number[],
): T[] {
    const hidden = new Set(dismissed);
    return transfers.filter(
        (transfer) =>
            !canClearTransfer(transfer) || !hidden.has(transfer.operation_id),
    );
}
