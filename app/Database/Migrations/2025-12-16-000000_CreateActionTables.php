<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateActionTables extends Migration
{
    public function up()
    {
        // Table: actions
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'action'       => ['type' => 'VARCHAR', 'constraint' => 191],
            'callback'     => ['type' => 'VARCHAR', 'constraint' => 191],
            'payload'      => ['type' => 'TEXT'],
            'status'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'priority'     => ['type' => 'INT', 'constraint' => 3, 'default' => 10],
            'recurring'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'signature'    => ['type' => 'VARCHAR', 'constraint' => 32],
            'created_at'   => ['type' => 'INT', 'unsigned' => true],
            'updated_at'   => ['type' => 'INT', 'unsigned' => true],
            'scheduled_at' => ['type' => 'INT', 'unsigned' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['status', 'priority', 'scheduled_at']);
        $this->forge->addKey('signature');
        $this->forge->createTable('actions');

        // Table: action_logs
        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'action_id'  => ['type' => 'BIGINT', 'unsigned' => true],
            'status'     => ['type' => 'TINYINT', 'constraint' => 1],
            'message'    => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'INT', 'unsigned' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('action_id', 'actions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('action_logs');
    }

    public function down()
    {
        $this->forge->dropTable('action_logs');
        $this->forge->dropTable('actions');
    }
}