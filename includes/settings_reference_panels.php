<?php
declare(strict_types=1);

/** @var array<int,array<string,mixed>> $departments */
/** @var array<int,array<string,mixed>> $categories */
/** @var array<int,array<string,mixed>> $priorities */
/** @var array<int,array<string,mixed>> $faqRows */
$deptActiveCol = ref_table_has_is_active('departments');
$catActiveCol = ref_table_has_is_active('ticket_categories');
$priActiveCol = ref_table_has_is_active('ticket_priorities');
?>
<div class="col-12">
    <div class="card-surface p-3 p-lg-4 mb-0">
        <h2 class="h6 fw-bold mb-3">Departments</h2>
        <div class="table-responsive mb-2">
            <table class="table table-sm">
                <thead><tr><th>Name</th><?php if ($deptActiveCol) : ?><th>Active</th><?php endif; ?><th></th></tr></thead>
                <tbody>
                <?php foreach ($departments as $d) : ?>
                    <tr>
                        <td><?php echo e($d['department_name']); ?></td>
                        <?php if ($deptActiveCol) : ?>
                            <td><?php echo !empty($d['is_active']) ? 'Yes' : 'No'; ?></td>
                        <?php endif; ?>
                        <td class="text-end">
                            <?php if ($deptActiveCol) : ?>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="toggle_department">
                                    <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                                    <input type="hidden" name="is_active" value="<?php echo !empty($d['is_active']) ? '0' : '1'; ?>">
                                    <button class="btn btn-link btn-sm p-0" type="submit"><?php echo !empty($d['is_active']) ? 'Disable' : 'Enable'; ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="post" class="row g-2">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="add_department">
            <div class="col-md-8"><input class="form-control form-control-sm" name="department_name" placeholder="New department" required></div>
            <div class="col-md-4"><button class="btn btn-outline-muted btn-sm w-100" type="submit">Add department</button></div>
        </form>
    </div>
</div>

<div class="col-lg-6">
    <div class="card-surface p-3 p-lg-4 h-100">
        <h2 class="h6 fw-bold mb-3">Ticket categories</h2>
        <ul class="list-unstyled small mb-2">
            <?php foreach ($categories as $c) : ?>
                <li class="d-flex justify-content-between border-bottom py-1">
                    <span><?php echo e($c['category_name']); ?><?php echo ($catActiveCol && empty($c['is_active'])) ? ' (disabled)' : ''; ?></span>
                    <?php if ($catActiveCol) : ?>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="action" value="toggle_category">
                            <input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
                            <input type="hidden" name="is_active" value="<?php echo !empty($c['is_active']) ? '0' : '1'; ?>">
                            <button class="btn btn-link btn-sm p-0" type="submit"><?php echo !empty($c['is_active']) ? 'Disable' : 'Enable'; ?></button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <form method="post" class="d-flex gap-2">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="add_category">
            <input class="form-control form-control-sm" name="category_name" placeholder="New category" required>
            <button class="btn btn-outline-muted btn-sm" type="submit">Add</button>
        </form>
    </div>
</div>

<div class="col-lg-6">
    <div class="card-surface p-3 p-lg-4 h-100">
        <h2 class="h6 fw-bold mb-3">Priority levels</h2>
        <ul class="list-unstyled small mb-2">
            <?php foreach ($priorities as $p) : ?>
                <li class="d-flex justify-content-between border-bottom py-1">
                    <span><?php echo e($p['priority_name']); ?> (<?php echo (int) $p['priority_level']; ?>)<?php echo ($priActiveCol && empty($p['is_active'])) ? ' (disabled)' : ''; ?></span>
                    <?php if ($priActiveCol) : ?>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="action" value="toggle_priority">
                            <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
                            <input type="hidden" name="is_active" value="<?php echo !empty($p['is_active']) ? '0' : '1'; ?>">
                            <button class="btn btn-link btn-sm p-0" type="submit"><?php echo !empty($p['is_active']) ? 'Disable' : 'Enable'; ?></button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <form method="post" class="row g-2">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="add_priority">
            <div class="col-7"><input class="form-control form-control-sm" name="priority_name" placeholder="Name" required></div>
            <div class="col-3"><input class="form-control form-control-sm" name="priority_level" type="number" min="1" value="1" title="Level"></div>
            <div class="col-2"><button class="btn btn-outline-muted btn-sm w-100" type="submit">Add</button></div>
        </form>
    </div>
</div>

<div class="col-12">
    <div class="card-surface p-3 p-lg-4">
        <h2 class="h6 fw-bold mb-3">FAQ management</h2>
        <p class="small text-muted">Public FAQ page reads from these records (<code>faqs</code> table).</p>
        <?php foreach ($faqRows as $fq) : ?>
            <details class="mb-2 border rounded p-2 small">
                <summary class="fw-semibold"><?php echo e($fq['question']); ?> <span class="text-muted">(<?php echo e($fq['category']); ?>)<?php echo empty($fq['is_active']) ? ' — disabled' : ''; ?></span></summary>
                <form method="post" class="mt-2">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="edit_faq">
                    <input type="hidden" name="id" value="<?php echo (int) $fq['id']; ?>">
                    <input class="form-control form-control-sm mb-1" name="category" value="<?php echo e($fq['category']); ?>">
                    <input class="form-control form-control-sm mb-1" name="question" value="<?php echo e($fq['question']); ?>" required>
                    <textarea class="form-control form-control-sm mb-1" name="answer" rows="3" required><?php echo e($fq['answer']); ?></textarea>
                    <div class="d-flex gap-2">
                        <button class="btn btn-accent btn-sm" type="submit">Save</button>
                    </div>
                </form>
                <form method="post" class="d-inline mt-1">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="toggle_faq">
                    <input type="hidden" name="id" value="<?php echo (int) $fq['id']; ?>">
                    <input type="hidden" name="is_active" value="<?php echo !empty($fq['is_active']) ? '0' : '1'; ?>">
                    <button class="btn btn-link btn-sm p-0 text-danger" type="submit"><?php echo !empty($fq['is_active']) ? 'Disable FAQ' : 'Enable FAQ'; ?></button>
                </form>
            </details>
        <?php endforeach; ?>
        <form method="post" class="border-top pt-3 mt-2">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="add_faq">
            <div class="row g-2">
                <div class="col-md-3"><input class="form-control form-control-sm" name="category" placeholder="Category" value="general"></div>
                <div class="col-md-9"><input class="form-control form-control-sm" name="question" placeholder="Question" required></div>
                <div class="col-12"><textarea class="form-control form-control-sm" name="answer" rows="2" placeholder="Answer" required></textarea></div>
                <div class="col-12"><button class="btn btn-outline-muted btn-sm" type="submit">Add FAQ</button></div>
            </div>
        </form>
    </div>
</div>

