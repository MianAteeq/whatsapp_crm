<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $templates = [
            [
                'name' => 'Modern Executive (Navy & Slate Frame)',
                'subject' => 'Partnership inquiry for {{business_name}}',
                'category' => 'Sales',
                'html_body' => '<table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #f1f5f9; padding: 30px 15px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
  <tr>
    <td align="center">
      <table width="100%" border="0" cellspacing="0" cellpadding="0" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
        <!-- PROFESSIONAL HEADER -->
        <tr>
          <td style="background-color: #0f172a; padding: 24px 32px; border-bottom: 3px solid #3b82f6;">
            <table width="100%" border="0" cellspacing="0" cellpadding="0">
              <tr>
                <td>
                  <span style="color: #ffffff; font-size: 18px; font-weight: 800; letter-spacing: -0.5px; text-transform: uppercase;">{{from_name}}</span>
                  <span style="display: block; color: #94a3b8; font-size: 12px; margin-top: 3px; font-weight: 500;">Executive Business Development</span>
                </td>
                <td align="right">
                  <span style="display: inline-block; background-color: rgba(59, 130, 246, 0.2); color: #60a5fa; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.5px;">Verified Partner</span>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <!-- EDITABLE BODY TEXT -->
        <tr>
          <td style="padding: 36px 32px; color: #334155; font-size: 15px; line-height: 1.6;">
            <p style="margin: 0 0 16px 0; font-size: 15px; color: #1e293b;">Hi {{first_name | default: "there"}},</p>
            <p style="margin: 0 0 16px 0;">I noticed <strong>{{business_name}}</strong> and was really impressed by your reputation and market presence.</p>
            <p style="margin: 0 0 16px 0;">We recently helped companies similar to yours increase their qualified inquiries and streamline client scheduling. I wanted to share a few specific ideas tailored for your team.</p>
            <table cellspacing="0" cellpadding="0" border="0" style="margin: 24px 0;">
              <tr>
                <td style="border-radius: 8px; background-color: #2563eb; text-align: center;">
                  <a href="https://calendly.com" target="_blank" style="background-color: #2563eb; border: 1px solid #2563eb; border-radius: 8px; font-family: sans-serif; font-size: 14px; font-weight: 700; text-decoration: none; padding: 12px 24px; color: #ffffff; display: inline-block;">
                    Schedule a 10-Minute Call &rarr;
                  </a>
                </td>
              </tr>
            </table>
            <p style="margin: 0 0 16px 0;">Would you be open to a brief 10-minute chat this week?</p>
            <p style="margin: 0; line-height: 1.5;">Best regards,<br><strong style="color: #0f172a;">{{from_name}}</strong><br><span style="font-size: 13px; color: #64748b;">Growth & Strategy</span></p>
          </td>
        </tr>
        <!-- PROFESSIONAL FOOTER -->
        <tr>
          <td style="background-color: #f8fafc; padding: 24px 32px; border-top: 1px solid #e2e8f0; text-align: center; color: #94a3b8; font-size: 11px; line-height: 1.5;">
            <p style="margin: 0 0 8px 0; color: #64748b;">You received this message regarding <strong>{{business_name}}</strong>.</p>
            <p style="margin: 0 0 12px 0;">Sent with care by {{from_name}} &bull; Direct Outreach &bull; All rights reserved.</p>
            <p style="margin: 0;">
              <a href="{{unsubscribe_url}}" style="color: #64748b; text-decoration: underline; font-weight: 600;">Unsubscribe from future emails</a>
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>',
                'text_body' => "Hi {{first_name | default: 'there'}},\n\nI noticed {{business_name}} and wanted to reach out regarding our partnership opportunities.\n\nBest regards,\n{{from_name}}",
            ],
            [
                'name' => 'Tech & SaaS (Indigo Gradient Frame)',
                'subject' => 'Quick idea for {{business_name}}',
                'category' => 'Introduction',
                'html_body' => '<table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #f8fafc; padding: 30px 15px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
  <tr>
    <td align="center">
      <table width="100%" border="0" cellspacing="0" cellpadding="0" style="max-width: 600px; background-color: #ffffff; border-radius: 16px; overflow: hidden; border: 1px solid #e0e7ff; box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.06);">
        <!-- SAAS INDIGO HEADER -->
        <tr>
          <td style="background: linear-gradient(135deg, #4338ca 0%, #6366f1 100%); padding: 28px 32px;">
            <table width="100%" border="0" cellspacing="0" cellpadding="0">
              <tr>
                <td>
                  <span style="color: #ffffff; font-size: 19px; font-weight: 800; letter-spacing: -0.5px;">{{from_name}}</span>
                  <span style="display: block; color: #c7d2fe; font-size: 12px; margin-top: 2px;">Intelligent Automation & Growth</span>
                </td>
                <td align="right">
                  <span style="background-color: rgba(255,255,255,0.2); color: #ffffff; font-size: 11px; font-weight: 700; padding: 5px 12px; border-radius: 20px;">SaaS Solutions</span>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <!-- EDITABLE BODY TEXT -->
        <tr>
          <td style="padding: 36px 32px; color: #334155; font-size: 15px; line-height: 1.6;">
            <p style="margin: 0 0 16px 0; font-size: 15px; color: #1e293b;">Hi {{first_name | default: "there"}},</p>
            <p style="margin: 0 0 16px 0;">We recently developed an automated outreach and CRM pipeline system specifically designed for {{industry | default: "modern businesses"}}.</p>
            <div style="background-color: #f5f3ff; border-left: 4px solid #7c3aed; border-radius: 8px; padding: 16px; margin: 20px 0;">
              <strong style="color: #5b21b6; font-size: 14px;">Why this matters for {{business_name}}:</strong>
              <p style="margin: 6px 0 0 0; color: #4c1d95; font-size: 13px;">Teams using this framework have seen a 35% reduction in manual data entry and a 2x increase in qualified lead bookings.</p>
            </div>
            <table cellspacing="0" cellpadding="0" border="0" style="margin: 24px 0;">
              <tr>
                <td style="border-radius: 8px; background-color: #4f46e5; text-align: center;">
                  <a href="https://" target="_blank" style="background-color: #4f46e5; border: 1px solid #4f46e5; border-radius: 8px; font-family: sans-serif; font-size: 14px; font-weight: 700; text-decoration: none; padding: 12px 26px; color: #ffffff; display: inline-block;">
                    Explore 10-Min Demo &rarr;
                  </a>
                </td>
              </tr>
            </table>
            <p style="margin: 0 0 16px 0;">Let me know if you would like me to send over a 2-minute overview video.</p>
            <p style="margin: 0; line-height: 1.5;">Best regards,<br><strong style="color: #1e1b4b;">{{from_name}}</strong></p>
          </td>
        </tr>
        <!-- FOOTER -->
        <tr>
          <td style="background-color: #faf5ff; padding: 22px 32px; border-top: 1px solid #ede9fe; text-align: center; color: #a78bfa; font-size: 11px;">
            <p style="margin: 0 0 6px 0; color: #6b21a8;">Delivered by {{from_name}} &bull; Certified Outreach Engine</p>
            <p style="margin: 0;"><a href="{{unsubscribe_url}}" style="color: #7c3aed; text-decoration: underline;">Unsubscribe</a></p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>',
                'text_body' => "Hi {{first_name | default: 'there'}},\n\nWe recently developed a growth framework for {{industry | default: 'modern businesses'}}.\n\nBest regards,\n{{from_name}}",
            ],
            [
                'name' => 'Clinic & Healthcare (Teal & Emerald Frame)',
                'subject' => 'Patient booking consultation for {{business_name}}',
                'category' => 'Appointment',
                'html_body' => '<table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #f0fdfa; padding: 30px 15px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
  <tr>
    <td align="center">
      <table width="100%" border="0" cellspacing="0" cellpadding="0" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #ccfbf1; box-shadow: 0 4px 6px -1px rgba(13, 148, 136, 0.06);">
        <!-- CLINICAL TEAL HEADER -->
        <tr>
          <td style="background-color: #0f766e; padding: 24px 32px; border-bottom: 3px solid #14b8a6;">
            <table width="100%" border="0" cellspacing="0" cellpadding="0">
              <tr>
                <td>
                  <span style="color: #ffffff; font-size: 18px; font-weight: 800; letter-spacing: -0.3px;">{{from_name}}</span>
                  <span style="display: block; color: #99f6e4; font-size: 12px; margin-top: 2px;">Healthcare & Clinic Solutions</span>
                </td>
                <td align="right">
                  <span style="background-color: rgba(20, 184, 166, 0.25); color: #ffffff; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px;">Health Partner</span>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <!-- EDITABLE BODY TEXT -->
        <tr>
          <td style="padding: 36px 32px; color: #334155; font-size: 15px; line-height: 1.6;">
            <p style="margin: 0 0 16px 0; font-size: 15px; color: #0f172a;">Dear {{first_name | default: "Doctor"}},</p>
            <p style="margin: 0 0 16px 0;">We noticed <strong>{{business_name}}</strong> and understand the importance of patient satisfaction and minimizing appointment no-shows.</p>
            <p style="margin: 0 0 16px 0;">Our dedicated WhatsApp reminder and patient portal software helps healthcare providers cut missed appointments by up to 40% while saving hours of staff time.</p>
            <table cellspacing="0" cellpadding="0" border="0" style="margin: 24px 0;">
              <tr>
                <td style="border-radius: 8px; background-color: #0d9488; text-align: center;">
                  <a href="https://" target="_blank" style="background-color: #0d9488; border: 1px solid #0d9488; border-radius: 8px; font-family: sans-serif; font-size: 14px; font-weight: 700; text-decoration: none; padding: 12px 26px; color: #ffffff; display: inline-block;">
                    Request Free Clinic Demonstration &rarr;
                  </a>
                </td>
              </tr>
            </table>
            <p style="margin: 0 0 16px 0;">Would you be available for a short 10-minute online walkthrough this week?</p>
            <p style="margin: 0; line-height: 1.5;">Warm regards,<br><strong style="color: #115e59;">{{from_name}}</strong><br><span style="font-size: 13px; color: #64748b;">Medical Practice Advisor</span></p>
          </td>
        </tr>
        <!-- CLINICAL FOOTER -->
        <tr>
          <td style="background-color: #f0fdfa; padding: 22px 32px; border-top: 1px solid #ccfbf1; text-align: center; color: #0f766e; font-size: 11px;">
            <p style="margin: 0 0 6px 0;">Confidential healthcare outreach for {{business_name}} &bull; Sent by {{from_name}}</p>
            <p style="margin: 0;"><a href="{{unsubscribe_url}}" style="color: #0d9488; text-decoration: underline;">Unsubscribe from medical outreach</a></p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>',
                'text_body' => "Dear {{first_name | default: 'Doctor'}},\n\nWe noticed {{business_name}}.\n\nWarm regards,\n{{from_name}}",
            ],
            [
                'name' => 'Follow-up (Clean Executive Minimal)',
                'subject' => 'Following up regarding {{business_name}}',
                'category' => 'Follow-up',
                'html_body' => '<table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #ffffff; padding: 24px 15px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
  <tr>
    <td align="center">
      <table width="100%" border="0" cellspacing="0" cellpadding="0" style="max-width: 580px; background-color: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0;">
        <!-- MINIMAL TOP ACCENT -->
        <tr>
          <td style="height: 4px; background-color: #3b82f6;"></td>
        </tr>
        <!-- BODY -->
        <tr>
          <td style="padding: 32px 28px; color: #334155; font-size: 15px; line-height: 1.6;">
            <p style="margin: 0 0 16px 0;">Hi {{first_name | default: "there"}},</p>
            <p style="margin: 0 0 16px 0;">I wanted to follow up quickly on my note from last week regarding {{business_name}}.</p>
            <p style="margin: 0 0 16px 0;">I know how busy you are, so I will keep this brief: are you open to a 5-minute chat to see if our growth solutions would be relevant for {{industry | default: "your business"}}?</p>
            <p style="margin: 20px 0;">If so, feel free to reply directly to this email or pick a quick slot here:</p>
            <table cellspacing="0" cellpadding="0" border="0" style="margin: 16px 0;">
              <tr>
                <td style="border-radius: 6px; background-color: #1e293b; text-align: center;">
                  <a href="https://calendly.com" target="_blank" style="background-color: #1e293b; border-radius: 6px; font-family: sans-serif; font-size: 13px; font-weight: 700; text-decoration: none; padding: 10px 20px; color: #ffffff; display: inline-block;">
                    Pick a 5-Minute Time &rarr;
                  </a>
                </td>
              </tr>
            </table>
            <p style="margin: 0; line-height: 1.5;">Best regards,<br><strong>{{from_name}}</strong></p>
          </td>
        </tr>
        <!-- COMPLIANCE FOOTER -->
        <tr>
          <td style="padding: 16px 28px; background-color: #f8fafc; border-top: 1px solid #f1f5f9; text-align: center; color: #94a3b8; font-size: 11px;">
            <p style="margin: 0;">If you\'d rather I not reach out again, you can <a href="{{unsubscribe_url}}" style="color: #64748b; text-decoration: underline;">unsubscribe here</a>.</p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>',
                'text_body' => "Hi {{first_name | default: 'there'}},\n\nI wanted to follow up quickly regarding {{business_name}}.\n\nBest regards,\n{{from_name}}",
            ],
            [
                'name' => 'VIP Product Announcement (Dark Header)',
                'subject' => 'Exclusive update for {{business_name}}',
                'category' => 'Newsletter',
                'html_body' => '<table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #0f172a; padding: 30px 15px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
  <tr>
    <td align="center">
      <table width="100%" border="0" cellspacing="0" cellpadding="0" style="max-width: 600px; background-color: #ffffff; border-radius: 14px; overflow: hidden;">
        <!-- LUXURY DARK HEADER -->
        <tr>
          <td style="background-color: #1e293b; padding: 30px 32px; text-align: center;">
            <span style="display: inline-block; background-color: #3b82f6; color: #ffffff; font-size: 10px; font-weight: 800; letter-spacing: 1px; padding: 4px 10px; border-radius: 12px; text-transform: uppercase;">Announcement</span>
            <h1 style="color: #ffffff; font-size: 22px; font-weight: 800; margin: 12px 0 4px 0; letter-spacing: -0.5px;">{{from_name}} VIP Briefing</h1>
            <p style="color: #94a3b8; font-size: 12px; margin: 0;">Special Insights & Solutions for {{business_name}}</p>
          </td>
        </tr>
        <!-- BODY -->
        <tr>
          <td style="padding: 36px 32px; color: #334155; font-size: 15px; line-height: 1.6;">
            <p style="margin: 0 0 16px 0; font-size: 15px; color: #0f172a;">Hello {{first_name | default: "there"}},</p>
            <p style="margin: 0 0 16px 0;">We are delighted to share our newest release designed to help <strong>{{business_name}}</strong> achieve better performance and operational speed.</p>
            <p style="margin: 0 0 16px 0;">Take a look at what is newly available for your team:</p>
            <table cellspacing="0" cellpadding="0" border="0" style="margin: 24px 0;">
              <tr>
                <td style="border-radius: 8px; background-color: #2563eb; text-align: center;">
                  <a href="https://" target="_blank" style="background-color: #2563eb; border-radius: 8px; font-family: sans-serif; font-size: 14px; font-weight: 700; text-decoration: none; padding: 12px 28px; color: #ffffff; display: inline-block;">
                    View New Features &rarr;
                  </a>
                </td>
              </tr>
            </table>
            <p style="margin: 0; line-height: 1.5;">Sincerely,<br><strong style="color: #0f172a;">The {{from_name}} Team</strong></p>
          </td>
        </tr>
        <!-- FOOTER -->
        <tr>
          <td style="background-color: #f1f5f9; padding: 22px 32px; text-align: center; color: #64748b; font-size: 11px;">
            <p style="margin: 0 0 6px 0;">Sent by {{from_name}} &bull; All rights reserved.</p>
            <p style="margin: 0;"><a href="{{unsubscribe_url}}" style="color: #2563eb; text-decoration: underline;">Unsubscribe</a></p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>',
                'text_body' => "Hello {{first_name | default: 'Leader'}},\n\nWe are delighted to share our newest release for {{business_name}}.\n\nSincerely,\n{{from_name}}",
            ],
        ];

        foreach ($tenants as $tenant) {
            foreach ($templates as $tmpl) {
                EmailTemplate::firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'name' => $tmpl['name'],
                    ],
                    [
                        'subject' => $tmpl['subject'],
                        'category' => $tmpl['category'],
                        'html_body' => $tmpl['html_body'],
                        'text_body' => $tmpl['text_body'],
                    ]
                );
            }
        }
    }
}
