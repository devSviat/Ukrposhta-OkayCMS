<?php

namespace Okay\Modules\Sviat\Ukrposhta\Groups;

use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Rows\OfficeKind;

/**
 * Форма груп у надісланому вигляді: `groups[i][id|name|enabled|delete]` (id "new" для групи, доданої
 * на сторінці) і `kinds[kind]` — id групи у формі, якій належить вид пунктів; "0" — вид прихований.
 */
final class GroupsInput
{
    private const int NAME_LENGTH = 255;

    /** @return array{groups: list<array{id:?int, name:string, enabled:bool, position:int, delete:bool, kinds:list<string>}>, errors: list<string>} */
    public static function parse(array $post): array
    {
        $rawGroups = is_array($post['groups'] ?? null) ? array_values($post['groups']) : [];
        if ($rawGroups === []) {
            return ['groups' => [], 'errors' => ['sviat__ukrposhta__groups_error_none']];
        }

        $rawKinds = is_array($post['kinds'] ?? null) ? $post['kinds'] : [];
        $referenced = array_map(static fn($ref): string => is_scalar($ref) ? (string)$ref : '', $rawKinds);
        $groups = [];
        $indexByFormId = [];
        $errors = [];
        foreach ($rawGroups as $raw) {
            $raw = is_array($raw) ? $raw : [];
            $formId = is_scalar($raw['id'] ?? null) ? (string)$raw['id'] : '';
            $name = mb_substr(trim(is_string($raw['name'] ?? null) ? $raw['name'] : ''), 0, self::NAME_LENGTH);
            $delete = !empty($raw['delete']);
            // Сторінка завжди містить один порожній рядок нової групи; якщо його лишено порожнім, це не група.
            if (!ctype_digit($formId) && $name === '' && !in_array($formId, $referenced, true)) {
                continue;
            }
            if ($name === '' && !$delete) {
                $errors[] = 'sviat__ukrposhta__groups_error_empty_name';
            }
            $indexByFormId[$formId] = count($groups);
            $groups[] = [
                'id' => ctype_digit($formId) && (int)$formId > 0 ? (int)$formId : null,
                'name' => $name,
                'enabled' => !empty($raw['enabled']),
                'position' => count($groups) + 1,
                'delete' => $delete,
                'kinds' => [],
            ];
        }

        foreach (OfficeKind::cases() as $kind) {
            $ref = $referenced[$kind->value] ?? '';
            if ($ref === '' || $ref === '0') {
                continue;
            }
            if (!isset($indexByFormId[$ref])) {
                $errors[] = 'sviat__ukrposhta__groups_error_other';
                continue;
            }
            $index = $indexByFormId[$ref];
            if ($groups[$index]['delete']) {
                $errors[] = 'sviat__ukrposhta__groups_error_deleted';
                continue;
            }
            $groups[$index]['kinds'][] = $kind->value;
        }

        return ['groups' => $groups, 'errors' => array_values(array_unique($errors))];
    }
}
