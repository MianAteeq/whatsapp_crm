<?php
declare(strict_types=1);
namespace App\Services\Workflow;
use App\Models\AutomationBotRule;
final class BotClassifier {
    private const CRAWLERS=['bot','crawler','spider','facebookexternalhit','slackbot','whatsapp','googleimageproxy','headless'];
    public function classify(int $tenantId, string $userAgent, array $metadata=[]): array {
        $agent=strtolower($userAgent);
        foreach(self::CRAWLERS as $needle) if(str_contains($agent,$needle)) return ['classification'=>'bot','reason'=>'known_crawler_user_agent'];
        if(($metadata['provider_bot']??false)===true) return ['classification'=>'bot','reason'=>'provider_indicator'];
        if(($metadata['datacenter_signal']??false)===true) return ['classification'=>'unknown','reason'=>'datacenter_ip_signal'];
        if(($metadata['recent_visits']??0)>20) return ['classification'=>'bot','reason'=>'suspicious_visit_frequency'];
        foreach(AutomationBotRule::where('tenant_id',$tenantId)->where('enabled',true)->get() as $rule) if($rule->signal_type==='user_agent' && @preg_match($rule->pattern,$userAgent)===1) return ['classification'=>$rule->classification,'reason'=>'custom_rule:'.$rule->id];
        if(str_contains($agent,'mozilla/') && !str_contains($agent,'headless')) return ['classification'=>'human','reason'=>'interactive_browser_user_agent'];
        return ['classification'=>'unknown','reason'=>'insufficient_signals'];
    }
}
