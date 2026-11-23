/**
 * Spreading a bill discount over the lines of an order when some dishes have a maximum discount. It follows
 * App\Services\DiscountAllocation on the server, so the POS shows the same discount the bill will get.
 */

const round2 = (value) => Math.round((Number(value) + Number.EPSILON) * 100) / 100;

/**
 * A percent discount: every line takes that percent of its amount, up to its limit.
 *
 * @param {number} percent
 * @param {number[]} bases What each line can be discounted from
 * @param {number[]} caps The most each line may still take
 */
export function allocatePercent(percent, bases, caps) {
    return bases.map((base, index) => round2(Math.max(0, Math.min(base * percent / 100, caps[index], base))));
}

/**
 * A fixed amount: shared by line amount. A line that would pass its limit keeps the limit and the rest is
 * shared again among the others.
 *
 * @param {number} amount
 * @param {number[]} bases
 * @param {number[]} caps
 */
export function allocateFixed(amount, bases, caps) {
    const shares = bases.map(() => 0);
    let open = bases.map((base, index) => index).filter((index) => caps[index] > 0 && bases[index] > 0);
    let remaining = Math.max(0, amount);

    while (remaining > 0.004 && open.length > 0) {
        const weight = open.reduce((sum, index) => sum + bases[index], 0);
        let capped = false;

        for (const index of open) {
            const room = Math.min(caps[index], bases[index]) - shares[index];

            if (remaining * bases[index] / weight >= room - 0.004) {
                shares[index] += room;
                remaining -= room;
                open = open.filter((entry) => entry !== index);
                capped = true;

                break;
            }
        }

        if (! capped) {
            for (const index of open) {
                shares[index] += remaining * bases[index] / weight;
            }

            remaining = 0;
        }
    }

    return shares.map(round2);
}
