<?php
// A foreach key and a foreach value share a name across two loops in a function that calls compact() with runtime names.
class Q {
    public array $vars = ['post_author' => 1, 'post_status' => 'publish'];
    public array $where = [];
    /** @param array<string,mixed> $f */
    public function ids(array $f): array {
        $fields = 'x';
        foreach ($f as $field_name => $field_value) {
            $this->where[$field_name] = "{$field_name} = " . $field_value;
        }
        foreach (array('post_status', 'post_type') as $field_name) {
            if (!empty($this->vars[$field_name])) {
                $this->where[$field_name] = "{$field_name} IN " . $this->vars[$field_name];
            }
        }
        $pieces = ['fields', 'where'];
        $where = implode(' AND ', $this->where);
        return compact($pieces);
    }
}
print_r((new Q)->ids(['post_author' => 3]));
