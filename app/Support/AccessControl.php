<?php

namespace App\Support;

class AccessControl
{
    public const MODULES = [
        'dashboard' => 'Dashboard',
        'contacts' => 'Contacts, tags & categories',
        'inbox' => 'Conversations & messages',
        'campaigns' => 'WhatsApp campaigns',
        'templates' => 'WhatsApp templates',
        'whatsapp' => 'WhatsApp settings & connections',
        'email_campaigns' => 'Email campaigns & AI agent',
        'email_templates' => 'Email templates',
        'email_settings' => 'Email settings & sending domains',
        'scraper' => 'Business scraper',
        'automation' => 'Workflows & automation',
        'users' => 'Workspace users',
    ];

    public static function permissions(): array
    {
        $permissions = [];
        foreach (self::MODULES as $module => $label) {
            $permissions[] = ['key' => "$module.view", 'module' => $module, 'group' => $label, 'label' => 'View'];
            if ($module !== 'dashboard') {
                $permissions[] = ['key' => "$module.manage", 'module' => $module, 'group' => $label, 'label' => 'Manage'];
            }
        }

        return $permissions;
    }

    public static function keys(): array
    {
        return array_column(self::permissions(), 'key');
    }

    public static function defaults(): array
    {
        return [
            'superadmin' => ['name' => 'Super Admin', 'description' => 'Full platform and workspace access.', 'permissions' => self::keys()],
            'admin' => ['name' => 'Administrator', 'description' => 'Full access to their workspace, including team management.', 'permissions' => self::keys()],
            'agent' => ['name' => 'Agent', 'description' => 'Manage conversations and contacts; view WhatsApp templates.', 'permissions' => ['dashboard.view', 'contacts.view', 'contacts.manage', 'inbox.view', 'inbox.manage', 'templates.view']],
            'user' => ['name' => 'User', 'description' => 'View the dashboard and contacts.', 'permissions' => ['dashboard.view', 'contacts.view']],
        ];
    }
}
