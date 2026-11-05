<?php /** جدول حركات مالية مشترك؛ يتوقع $tx */ ?>
<table class="tbl">
  <thead><tr><th>التاريخ</th><?= !empty($showUser) ? '<th>الوكيل</th>' : '' ?><th>النوع</th><th>المبلغ</th><th>الرصيد بعدها</th><th>التفاصيل</th><th>بواسطة</th></tr></thead>
  <tbody>
  <?php foreach ($tx as $t): ?>
    <tr><td class="small"><?= h(fmt_dt($t['created_at'])) ?></td>
    <?php if (!empty($showUser)): ?><td><a href="<?= h(url('user', ['id' => $t['user_id']])) ?>"><?= h($t['agent']) ?></a></td><?php endif; ?>
    <td><?= h(tx_label($t['type'])) ?></td>
    <td class="<?= (int) $t['amount'] < 0 ? 'neg' : 'pos' ?>" dir="ltr"><?= number_format((int) $t['amount']) ?></td>
    <td dir="ltr"><?= number_format((int) $t['balance_after']) ?></td>
    <td class="small"><?= $t['sub'] ? '<a href="' . h(url('subscriber', ['id' => $t['subscriber_id']])) . '">' . h($t['sub']) . '</a> ' : '' ?><?= $t['batch_id'] ? '<a href="' . h(url('batch', ['id' => $t['batch_id']])) . '">دفعة #' . (int) $t['batch_id'] . '</a> ' : '' ?><?= h($t['note']) ?></td>
    <td class="small"><?= h($t['by_user'] ?? '') ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$tx): ?><tr><td colspan="7" class="muted center">لا توجد حركات.</td></tr><?php endif; ?>
  </tbody>
</table>
