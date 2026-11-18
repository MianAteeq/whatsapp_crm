<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Workflow\WorkflowValidator;
use PHPUnit\Framework\TestCase;

class WorkflowValidatorTest extends TestCase
{
    public function test_valid_connected_workflow_passes(): void
    {
        $result=(new WorkflowValidator)->validate(
            [['id'=>'trigger','type'=>'trigger'],['id'=>'end','type'=>'end_workflow']],
            [['source_node_id'=>'trigger','target_node_id'=>'end']]
        );
        self::assertTrue($result['valid']);
        self::assertSame([], $result['errors']);
    }

    public function test_missing_trigger_and_dangling_connection_block_publish(): void
    {
        $result=(new WorkflowValidator)->validate(
            [['id'=>'action','type'=>'send_email']],
            [['source_node_id'=>'action','target_node_id'=>'missing']]
        );
        self::assertFalse($result['valid']);
        self::assertContains('trigger_count',array_column($result['errors'],'code'));
        self::assertContains('invalid_connection',array_column($result['errors'],'code'));
    }
}
