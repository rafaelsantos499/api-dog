<?php

namespace App\Console\Commands;

use App\Models\UserSession;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

class CleanExpiredTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected  $signature = 'clean:expired-tokens';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean expired personal access tokens';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = Carbon::now();

        $expiredTokens = PersonalAccessToken::whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->get();

        foreach ($expiredTokens as $token) {
            UserSession::where('personal_access_token_id', $token->id)->delete();
            $token->delete();
        }

        $this->info('Tokens e sessões expirados limpos com sucesso.');
    }
}
