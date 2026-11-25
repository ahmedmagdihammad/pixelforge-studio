<?php

namespace App\Http\Controllers;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pricing;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public const SIZES = ['1024x1024', '1536x1024', '1024x1536'];

    public const QUALITIES = ['low', 'medium', 'high'];

    public function __construct(
        private readonly Pricing $pricing,
        private readonly PixelforgeClient $pixelforge,
    ) {}

    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()->latest()->latest('id')->paginate(12);

        return Inertia::render('Generations', [
            'generations' => $generations->through(fn (Generation $g) => $g->toFrontend()),
            'sizes' => self::SIZES,
            'qualities' => self::QUALITIES,
        ]);
    }

    /**
     * Live cost estimate for the form, so the user sees the price before submitting.
     * Calculated on the server so it uses exactly the same code (and character counting) as the hold.
     */
    public function estimate(Request $request): JsonResponse
    {
        $input = $this->validated($request, forEstimate: true);
        $estimate = $this->estimateFor($input);

        return response()->json([
            'min' => Money::format($estimate['min']),
            'max' => Money::format($estimate['max']),
            'available' => Money::format($request->user()->availableNano()),
            'affordable' => $request->user()->availableNano() >= $estimate['max'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $input = $this->validated($request);
        $estimate = $this->estimateFor($input);

        $generation = DB::transaction(function () use ($request, $input, $estimate) {
            // Lock the user so two quick submissions can't both spend the same credits.
            $user = User::whereKey($request->user()->id)->lockForUpdate()->first();

            // Hold the most this can possibly cost; the real (lower or equal) price is charged when it finishes.
            if ($user->availableNano() < $estimate['max']) {
                throw ValidationException::withMessages([
                    'credits' => 'Not enough credits: this can cost up to '.Money::format($estimate['max'])
                        .' and you have '.Money::format($user->availableNano()).' available.',
                ]);
            }

            return $user->generations()->create([
                'idempotency_key' => (string) Str::uuid(),
                'status' => Generation::SUBMITTING,
                'prompt' => $input['prompt'],
                'size' => $input['size'],
                'quality' => $input['quality'],
                'reference_images' => $input['reference_images'],
                'estimated_min_charge_nano' => $estimate['min'],
                'estimated_max_charge_nano' => $estimate['max'],
            ]);
        });

        SubmitGeneration::dispatch($generation);

        return back()->with('success', 'Generation submitted. You\'ll be charged at most '.Money::format($estimate['max']).'.');
    }

    private function validated(Request $request, bool $forEstimate = false): array
    {
        // Reference images arrive one per line from the form; drop blank lines first.
        $request->merge([
            'reference_images' => array_values(array_filter(array_map('trim', (array) $request->input('reference_images', [])))),
        ]);

        $input = $request->validate([
            'prompt' => [$forEstimate ? 'nullable' : 'required', 'string', 'max:4000'],
            'size' => ['required', 'in:'.implode(',', self::SIZES)],
            'quality' => ['required', 'in:'.implode(',', self::QUALITIES)],
            'reference_images' => ['array', 'max:4'],
            'reference_images.*' => ['url:http,https', 'max:2048'],
        ], [
            'reference_images.max' => 'You can add at most 4 reference images.',
            'reference_images.*.url' => 'Each reference image must be an http(s) URL.',
        ]);

        return ['prompt' => (string) ($input['prompt'] ?? '')] + $input;
    }

    /** @return array{min: int, max: int} */
    private function estimateFor(array $input): array
    {
        try {
            $pricing = $this->pixelforge->pricing();
        } catch (PixelforgeException) {
            throw ValidationException::withMessages(['prompt' => 'Couldn\'t load Pixelforge\'s prices right now. Please try again.']);
        }

        return $this->pricing->estimate($input['prompt'], $input['size'], $input['quality'], count($input['reference_images']), $pricing);
    }
}
