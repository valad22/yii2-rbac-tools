<?php

use yii\db\Migration;

/**
 * Creates route_log table for logging user routes access
 */
class m000000_000000_create_route_log_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%route_log}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'role' => $this->string(64)->notNull(),
            'route' => $this->string(255)->notNull(),
            'method' => $this->string(10)->notNull(),
            'params' => $this->text()->null()->comment('GET/POST parameters in JSON format'),
            'error_code' => $this->integer()->null()->comment('HTTP error code if request failed'),
            'created_at' => $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);

        // add foreign key for table `user`
        $this->addForeignKey(
            'fk-route_log-user_id',
            '{{%route_log}}',
            'user_id',
            '{{%user}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        // create indexes
        $this->createIndex(
            'idx-route_log-user_id',
            '{{%route_log}}',
            'user_id'
        );
        $this->createIndex(
            'idx-route_log-role',
            '{{%route_log}}',
            'role'
        );
        $this->createIndex(
            'idx-route_log-created_at',
            '{{%route_log}}',
            'created_at'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk-route_log-user_id', '{{%route_log}}');
        $this->dropTable('{{%route_log}}');
    }
}
