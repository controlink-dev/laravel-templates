<?php

namespace Controlink\Templates\Output\Labels;

use RuntimeException;

/**
 * A batch of labels that cannot be printed whole (CGE-98).
 *
 * A roll with a label missing misaligns every label after it, so a batch is all or
 * nothing: when any label's barcode does not fit or cannot be encoded, nothing is
 * rendered, and the exception lists every failing label (not only the first) so the
 * host can name them all at once.
 */
final class LabelBatchFailed extends RuntimeException
{
    /**
     * @param  list<array{index: int, content: string, reason: 'does_not_fit'|'unencodable'|'marker_not_found'}>  $failures  `index` is the label's position in the batch; `marker_not_found` is a ZPL code slot whose box did not come back from the screenshot whole
     */
    public function __construct(public readonly array $failures)
    {
        parent::__construct(sprintf(
            '%d label(s) of the batch have a barcode that cannot be printed: %s.',
            count($failures),
            implode(', ', array_map(
                fn (array $failure): string => '#'.$failure['index'].' "'.$failure['content'].'" ('.$failure['reason'].')',
                $failures,
            )),
        ));
    }
}
