<?php
declare(strict_types=1);
namespace Chronicle;
final class Ads {
    public static function enabled(array $config,bool $public):bool {
        $a=$config['adsense']??[];
        return $public&&($config['public_indexing']??false)===true&&($config['local_test']??false)!==true
            &&($a['enabled']??false)===true&&($a['account_settings_verified']??false)===true
            &&preg_match('~^https://[A-Za-z0-9.-]+$~D',$config['origin']??'')===1
            &&is_string($a['client']??null)&&preg_match('/^ca-pub-[0-9]{16}$/D',$a['client'])===1
            &&is_string($a['slot']??null)&&preg_match('/^[0-9]{10}$/D',$a['slot'])===1;
    }
    public static function render(string $html,array $config,string $nonce):string {
        $a=$config['adsense'];
        // Only generated templates and sanitized legacy HTML reach this method.
        $html=preg_replace('/<script\b/','<script nonce="'.$nonce.'"',$html);
        $unit='<aside class="chronicle-ad" aria-label="広告"><span>スポンサーリンク</span><ins class="adsbygoogle" style="display:block" data-ad-client="'.$a['client'].'" data-ad-slot="'.$a['slot'].'" data-ad-format="auto" data-full-width-responsive="true"></ins></aside>';
        $scripts='<script nonce="'.$nonce.'" async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client='.$a['client'].'" crossorigin="anonymous"></script><script nonce="'.$nonce.'">(window.adsbygoogle=window.adsbygoogle||[]).push({});</script>';
        return str_replace('</body>',$unit.$scripts.'</body>',$html);
    }
    public static function policy(string $nonce):string {
        // Google's supported strict-CSP integration, limited to opted-in public pages.
        return "object-src 'none'; script-src 'nonce-".$nonce."' 'unsafe-inline' 'unsafe-eval' 'strict-dynamic' https: http:; base-uri 'none'; frame-ancestors 'none'; form-action 'self'";
    }
}
