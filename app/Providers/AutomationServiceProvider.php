<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Workflow\Triggers\TriggerRegistry;
use App\Services\Workflow\Conditions\ConditionRegistry;
use App\Services\Workflow\Actions\ActionRegistry;

// Triggers
use App\Services\Workflow\Triggers\IncomingMessageTrigger;
use App\Services\Workflow\Triggers\KeywordTrigger;
use App\Services\Workflow\Triggers\ContactCreatedTrigger;
use App\Services\Workflow\Triggers\ContactUpdatedTrigger;
use App\Services\Workflow\Triggers\BirthdayTrigger;

// Conditions
use App\Services\Workflow\Conditions\TagCondition;
use App\Services\Workflow\Conditions\KeywordCondition;
use App\Services\Workflow\Conditions\TimeCondition;
use App\Services\Workflow\Conditions\CountryCondition;
use App\Services\Workflow\Conditions\LanguageCondition;
use App\Services\Workflow\Conditions\BusinessHoursCondition;
use App\Services\Workflow\Conditions\BirthdayCondition;

// Actions
use App\Services\Workflow\Actions\SendMessageAction;
use App\Services\Workflow\Actions\SendTemplateAction;
use App\Services\Workflow\Actions\AssignAgentAction;
use App\Services\Workflow\Actions\AddTagAction;
use App\Services\Workflow\Actions\RemoveTagAction;
use App\Services\Workflow\Actions\WaitAction;
use App\Services\Workflow\Actions\WebhookAction;
use App\Services\Workflow\Actions\EndWorkflowAction;

class AutomationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 1. Register Trigger Registry
        $this->app->singleton(TriggerRegistry::class, function () {
            $registry = new TriggerRegistry();

            // Default triggers mapping
            $registry->register('incoming_message', IncomingMessageTrigger::class);
            $registry->register('keyword', KeywordTrigger::class);
            $registry->register('contact_created', ContactCreatedTrigger::class);
            $registry->register('contact_updated', ContactUpdatedTrigger::class);
            $registry->register('birthday', BirthdayTrigger::class);
            $registry->register('schedule', BirthdayTrigger::class); // Birthday run daily mapping
            $registry->register('trigger', IncomingMessageTrigger::class); // Fallback / generic trigger

            return $registry;
        });

        // 2. Register Condition Registry
        $this->app->singleton(ConditionRegistry::class, function () {
            $registry = new ConditionRegistry();

            // Default conditions mapping
            $registry->register('condition', TagCondition::class); // Generic fallback
            $registry->register('tag_condition', TagCondition::class);
            $registry->register('keyword_condition', KeywordCondition::class);
            $registry->register('time_condition', TimeCondition::class);
            $registry->register('birthday_condition', BirthdayCondition::class);
            $registry->register('country_condition', CountryCondition::class);
            $registry->register('language_condition', LanguageCondition::class);
            $registry->register('business_hours_condition', BusinessHoursCondition::class);

            return $registry;
        });

        // 3. Register Action Registry
        $this->app->singleton(ActionRegistry::class, function () {
            $registry = new ActionRegistry();

            // Default actions mapping
            $registry->register('send_message', SendMessageAction::class);
            $registry->register('send_template', SendTemplateAction::class);
            $registry->register('assign_agent', AssignAgentAction::class);
            $registry->register('add_tag', AddTagAction::class);
            $registry->register('remove_tag', RemoveTagAction::class);
            $registry->register('wait', WaitAction::class);
            $registry->register('delay', WaitAction::class); // Mapped from the generic delay database field
            $registry->register('webhook', WebhookAction::class);
            $registry->register('end_workflow', EndWorkflowAction::class);

            return $registry;
        });
    }

    public function boot(): void
    {
        //
    }
}
