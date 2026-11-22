import { useConfirm } from '@/components/ui-kit/useConfirm.js';
import { markRefusal } from '@/lib/notify';
import { t } from '@/lib/i18n';

/**
 * Tell the user, in a warning dialog they must acknowledge, that the server refused what they asked
 * (for example deleting a dish that is on past orders). A toast is too easy to miss and, worse,
 * could be read as success.
 */
export function showRefusal(message) {
    markRefusal(message);

    return useConfirm()({
        variant: 'warning',
        level: 'simple',
        hideCancel: true,
        title: t('common.action_blocked'),
        message: String(message),
        confirmText: t('common.understood'),
    });
}
