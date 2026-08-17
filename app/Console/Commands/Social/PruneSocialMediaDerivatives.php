<?php

declare(strict_types=1);

namespace App\Console\Commands\Social;

use App\Support\Social\SocialMediaDerivativeDirectory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('social:prune-derivatives')]
#[Description('Delete hosted social-media image derivatives (aspect-ratio crops, TikTok resized photos) once no platform still needs to pull them')]
class PruneSocialMediaDerivatives extends Command
{
    private const MAX_AGE_HOURS = 1;

    /**
     * Every directory a publisher hosts a pull-from-URL derivative in. Add a
     * new network's directory here - nowhere else - when it starts hosting
     * derivatives a platform fetches asynchronously.
     *
     * @var list<string>
     */
    private const DIRECTORIES = [
        SocialMediaDerivativeDirectory::CROPS,
        SocialMediaDerivativeDirectory::TIKTOK_PHOTOS,
    ];

    public function handle(): int
    {
        $threshold = now()->subHours(self::MAX_AGE_HOURS)->getTimestamp();
        $pruned = 0;

        foreach (self::DIRECTORIES as $directory) {
            foreach (Storage::files($directory) as $path) {
                $modifiedAt = Storage::lastModified($path);

                if ($modifiedAt !== false && $modifiedAt < $threshold && Storage::delete($path)) {
                    $pruned++;
                }
            }
        }

        $this->info("Pruned {$pruned} social media derivative(s).");

        return self::SUCCESS;
    }
}
