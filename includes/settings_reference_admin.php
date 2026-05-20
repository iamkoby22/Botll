<?php
declare(strict_types=1);

function settings_handle_reference_post(PDO $pdo, bool $isSuper, array &$errors, string &$success): void
{
    if (!$isSuper || !is_post()) {
        return;
    }
    $action = (string) ($_POST['action'] ?? '');
    $hasActive = static fn (string $t) => ref_table_has_is_active($t);

    if ($action === 'add_department') {
        $name = trim((string) ($_POST['department_name'] ?? ''));
        if ($name === '') {
            $errors[] = 'Department name required.';
        } else {
            try {
                $pdo->prepare('INSERT INTO departments (department_name) VALUES (?)')->execute([$name]);
                $success = 'Department added.';
            } catch (Throwable $e) {
                $errors[] = 'Could not add department.';
            }
        }
    } elseif ($action === 'edit_department') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['department_name'] ?? ''));
        if ($id < 1 || $name === '') {
            $errors[] = 'Invalid department.';
        } else {
            $pdo->prepare('UPDATE departments SET department_name = ? WHERE id = ?')->execute([$name, $id]);
            $success = 'Department updated.';
        }
    } elseif ($action === 'toggle_department' && $hasActive('departments')) {
        $id = (int) ($_POST['id'] ?? 0);
        $active = (int) ($_POST['is_active'] ?? 0) ? 1 : 0;
        $pdo->prepare('UPDATE departments SET is_active = ? WHERE id = ?')->execute([$active, $id]);
        $success = 'Department status updated.';
    } elseif ($action === 'add_category') {
        $name = trim((string) ($_POST['category_name'] ?? ''));
        if ($name === '') {
            $errors[] = 'Category name required.';
        } else {
            try {
                $pdo->prepare('INSERT INTO ticket_categories (category_name) VALUES (?)')->execute([$name]);
                $success = 'Category added.';
            } catch (Throwable $e) {
                $errors[] = 'Could not add category.';
            }
        }
    } elseif ($action === 'edit_category') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['category_name'] ?? ''));
        if ($id < 1 || $name === '') {
            $errors[] = 'Invalid category.';
        } else {
            $pdo->prepare('UPDATE ticket_categories SET category_name = ? WHERE id = ?')->execute([$name, $id]);
            $success = 'Category updated.';
        }
    } elseif ($action === 'toggle_category' && $hasActive('ticket_categories')) {
        $id = (int) ($_POST['id'] ?? 0);
        $active = (int) ($_POST['is_active'] ?? 0) ? 1 : 0;
        $pdo->prepare('UPDATE ticket_categories SET is_active = ? WHERE id = ?')->execute([$active, $id]);
        $success = 'Category status updated.';
    } elseif ($action === 'add_priority') {
        $name = trim((string) ($_POST['priority_name'] ?? ''));
        $level = (int) ($_POST['priority_level'] ?? 1);
        if ($name === '') {
            $errors[] = 'Priority name required.';
        } else {
            try {
                $pdo->prepare('INSERT INTO ticket_priorities (priority_name, priority_level) VALUES (?,?)')->execute([$name, max(1, $level)]);
                $success = 'Priority added.';
            } catch (Throwable $e) {
                $errors[] = 'Could not add priority.';
            }
        }
    } elseif ($action === 'edit_priority') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['priority_name'] ?? ''));
        $level = (int) ($_POST['priority_level'] ?? 1);
        if ($id < 1 || $name === '') {
            $errors[] = 'Invalid priority.';
        } else {
            $pdo->prepare('UPDATE ticket_priorities SET priority_name = ?, priority_level = ? WHERE id = ?')->execute([$name, max(1, $level), $id]);
            $success = 'Priority updated.';
        }
    } elseif ($action === 'toggle_priority' && $hasActive('ticket_priorities')) {
        $id = (int) ($_POST['id'] ?? 0);
        $active = (int) ($_POST['is_active'] ?? 0) ? 1 : 0;
        $pdo->prepare('UPDATE ticket_priorities SET is_active = ? WHERE id = ?')->execute([$active, $id]);
        $success = 'Priority status updated.';
    } elseif ($action === 'add_faq') {
        $q = trim((string) ($_POST['question'] ?? ''));
        $a = trim((string) ($_POST['answer'] ?? ''));
        $cat = trim((string) ($_POST['category'] ?? 'general'));
        if ($q === '' || $a === '') {
            $errors[] = 'FAQ question and answer required.';
        } else {
            $pdo->prepare('INSERT INTO faqs (question, answer, category, is_active) VALUES (?,?,?,1)')->execute([$q, $a, $cat !== '' ? $cat : 'general']);
            $success = 'FAQ added.';
        }
    } elseif ($action === 'edit_faq') {
        $id = (int) ($_POST['id'] ?? 0);
        $q = trim((string) ($_POST['question'] ?? ''));
        $a = trim((string) ($_POST['answer'] ?? ''));
        $cat = trim((string) ($_POST['category'] ?? 'general'));
        if ($id < 1 || $q === '' || $a === '') {
            $errors[] = 'Invalid FAQ.';
        } else {
            $pdo->prepare('UPDATE faqs SET question=?, answer=?, category=? WHERE id=?')->execute([$q, $a, $cat, $id]);
            $success = 'FAQ updated.';
        }
    } elseif ($action === 'toggle_faq') {
        $id = (int) ($_POST['id'] ?? 0);
        $active = (int) ($_POST['is_active'] ?? 0) ? 1 : 0;
        $pdo->prepare('UPDATE faqs SET is_active = ? WHERE id = ?')->execute([$active, $id]);
        $success = 'FAQ status updated.';
    }
}

function settings_reference_data(PDO $pdo): array
{
    $deptSql = ref_table_has_is_active('departments')
        ? 'SELECT * FROM departments ORDER BY is_active DESC, department_name'
        : 'SELECT * FROM departments ORDER BY department_name';
    $catSql = ref_table_has_is_active('ticket_categories')
        ? 'SELECT * FROM ticket_categories ORDER BY is_active DESC, category_name'
        : 'SELECT * FROM ticket_categories ORDER BY category_name';
    $priSql = ref_table_has_is_active('ticket_priorities')
        ? 'SELECT * FROM ticket_priorities ORDER BY is_active DESC, priority_level'
        : 'SELECT * FROM ticket_priorities ORDER BY priority_level';
    $faqs = [];
    try {
        $faqs = $pdo->query('SELECT * FROM faqs ORDER BY category, id')->fetchAll();
    } catch (Throwable $e) {
        $faqs = [];
    }
    return [
        'departments' => $pdo->query($deptSql)->fetchAll(),
        'categories' => $pdo->query($catSql)->fetchAll(),
        'priorities' => $pdo->query($priSql)->fetchAll(),
        'faqs' => $faqs,
    ];
}
