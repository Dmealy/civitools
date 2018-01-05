<?php
namespace Urbics\Civitools\Console\Migrations;

use Urbics\Civitools\Migrations\GeneratorException;

class SyntaxBuilder
{
    /**
     * A template to be inserted.
     *
     * @var string
     */
    private $template;

    /**
     * Create the PHP syntax for the given schema.
     *
     * @param  array $schema
     * @param  array $meta
     * @return string
     */
    public function create($schema, $meta)
    {
        $up = $this->createSchemaForUpMethod($schema, $meta);
        $down = $this->createSchemaForDownMethod($schema, $meta);

        return compact('up', 'down');
    }

    /**
     * Create the schema for the "up" method.
     *
     * @param  string $schema
     * @param  array $meta
     * @return string
     * @throws GeneratorException
     */
    private function createSchemaForUpMethod($schema, $meta)
    {
        $action = explode('_', $meta['action']);
        $schemaType = (empty($action[1]) ? 'Column' : title_case($action[1]));
        $fields = $this->constructSchema($schema, ($action == 'remove' ? 'Drop' : 'Add'), $schemaType);
        if (in_array($meta['action'], ['create', 'create_function', 'create_trigger', 'update', 'remove'])) {
            return $this->insert($fields)->into($this->getSchemaWrapper($meta['action']), 'schema_up');
        }

        // Otherwise, we have no idea how to proceed.
        throw new GeneratorException;
    }

    /**
     * Construct the syntax for a down field.
     *
     * @param  array $schema
     * @param  array $meta
     * @return string
     * @throws GeneratorException
     */
    private function createSchemaForDownMethod($schema, $meta)
    {
        $action = explode('_', $meta['action']);
        $schemaType = (empty($action[1]) ? 'Column' : title_case($action[1]));
        $fields = $this->constructSchema($schema, 'Drop', $schemaType);
        if (in_array($meta['action'], ['create_function', 'create_trigger', 'update', 'remove'])) {
            return $this->insert($fields)->into($this->getSchemaWrapper($meta['action']), 'schema_down');
        }

        // If the user created a table, then for the down
        // method, we should drop it.
        if ($meta['action'] == 'create') {
            return sprintf("Schema::drop('%s');", $schema['name']);
        }

        // Otherwise, we have no idea how to proceed.
        throw new GeneratorException;
    }

    /**
     * Store the given template, to be inserted somewhere.
     *
     * @param  string $template
     * @return $this
     */
    private function insert($template)
    {
        $this->template = $template;

        return $this;
    }

    /**
     * Get the stored template, and insert into the given wrapper.
     *
     * @param  string $wrapper
     * @param  string $placeholder
     * @return mixed
     */
    private function into($wrapper, $placeholder = 'schema_up')
    {
        return str_replace('{{' . $placeholder . '}}', $this->template, $wrapper);
    }

    private function getSchemaWrapper($action)
    {
        $wrapper = "/Migrations/Stubs/schema-" . str_replace('_', '-', $action) . ".stub";
        return file_get_contents(dirname(__DIR__) . $wrapper);
    }

    /**
     * Construct the schema fields.
     *
     * @param  array $schema
     * @param  string $direction
     * @return array
     */
    private function constructSchema($schema, $direction = 'Add', $schemaType = 'Column')
    {
        if (!$schema) {
            return '';
        }
        $fields = array_map(function ($field) use ($direction, $schemaType) {
            $method = camel_case("{$direction}{$schemaType}");

            return $this->$method($field);
        }, $schema['fields']);

        return implode("\n" . str_repeat(' ', 12), $fields);
    }


    /**
     * Construct the syntax to add a column.
     *
     * @param  string $field
     * @return string
     */
    private function addColumn($field)
    {
        if (in_array($field['type'], ['index', 'unique', 'primary', 'foreign'])) {
            // Indexes have the optional index name as the second argument
            $syntax = sprintf("\$table->%s(%s, '%s')", $field['type'], $field['arguments'], $field['name']);
            if (!empty($field['options'])) {
                foreach ($field['options'] as $method => $value) {
                    $syntax .= sprintf("->%s(%s)", $method, $value === true ? '' : $value);
                }
            }
            return $syntax .= ';';
        }

        $syntax = sprintf("\$table->%s('%s')", $field['type'], $field['name']);

        // If there are arguments for the schema type, like decimal('amount', 5, 2)
        // then we have to remember to work those in.
        if (!empty($field['arguments'])) {
            $syntax = substr($syntax, 0, -1) . ', ';
            $syntax .= $field['arguments'] . ')';
        }

        if (!empty($field['options'])) {
            foreach ($field['options'] as $method => $value) {
                $syntax .= sprintf("->%s(%s)", $method, $value === true ? '' : $value);
            }
        }

        return $syntax .= ';';
    }

    /**
     * Construct the syntax to add a function.
     *
     * @param  string $field
     * @return string
     */
    private function addFunction($field)
    {
        return "DB::unprepared(\"" . $field['sql_up'] . "\");\n";
    }

    /**
     * Construct the syntax to drop a function.
     *
     * @param  string $field
     * @return string
     */
    private function dropFunction($field)
    {
        return "DB::unprepared(\"" . $field['sql_down'] . "\");";
    }

    /**
     * Construct the syntax to add a trigger.
     *
     * @param  string $field
     * @return string
     */
    private function addTrigger($field)
    {
        return "DB::unprepared(\"" . $field['sql_up'] . "\");\n";
    }

    /**
     * Construct the syntax to drop a trigger.
     *
     * @param  string $field
     * @return string
     */
    private function dropTrigger($field)
    {
        return "DB::unprepared(\"" . $field['sql_down'] . "\");";
    }

    /**
     * Construct the syntax to drop a column.
     *
     * @param  string $field
     * @return string
     */
    private function dropColumn($field)
    {
        if (in_array($field['type'], ['index', 'unique', 'primary', 'foreign'])) {
            return sprintf("\$table->drop%s('%s');", title_case($field['type']), $field['name']);
        }
        return sprintf("\$table->dropColumn('%s');", $field['name']);
    }
}
