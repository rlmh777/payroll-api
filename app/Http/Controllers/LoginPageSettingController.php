<?php

namespace App\Http\Controllers;

use App\Models\LoginPageSetting;
use App\Models\User;
use App\Support\Access;
use App\Support\ConfiguredStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LoginPageSettingController extends Controller
{
    public function publicShow(): JsonResponse
    {
        return response()->json(LoginPageSetting::current()->present());
    }

    public function show(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! Access::can($actor, 'view-login-page')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(LoginPageSetting::current()->present());
    }

    public function update(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! Access::can($actor, 'login-page-crud')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'backgroundMode' => ['required', 'in:color,image,carousel'],
            'backgroundColor' => ['required', 'string', 'max:16'],
            'carouselIntervalMs' => ['sometimes', 'integer', 'min:2000', 'max:30000'],
            'images' => ['sometimes', 'array'],
            'images.*.id' => ['required', 'string', 'max:64'],
            'images.*.path' => ['required', 'string', 'max:500'],
            'backgroundImageIds' => ['sometimes', 'array'],
            'backgroundImageIds.*' => ['string', 'max:64'],
            'blocks' => ['required', 'array'],
            'blocks.*.id' => ['required', 'string', 'max:64'],
            'blocks.*.type' => ['required', 'in:form,heading,message,image'],
            'blocks.*.x' => ['required', 'numeric'],
            'blocks.*.y' => ['required', 'numeric'],
            'blocks.*.width' => ['required', 'numeric'],
            'blocks.*.height' => ['required', 'numeric'],
            'blocks.*.zIndex' => ['sometimes', 'integer'],
            'blocks.*.text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'blocks.*.fontSize' => ['sometimes', 'numeric'],
            'blocks.*.color' => ['sometimes', 'string', 'max:16'],
            'blocks.*.align' => ['sometimes', 'in:left,center,right'],
            'blocks.*.imageId' => ['sometimes', 'nullable', 'string', 'max:64'],
            'blocks.*.maxWidth' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:960'],
        ]);

        $settings = LoginPageSetting::current();
        $current = LoginPageSetting::normalizeLayout(
            is_array($settings->layout) ? $settings->layout : LoginPageSetting::defaultLayout(),
        );

        $validated['images'] = $current['images'];
        $settings->layout = LoginPageSetting::normalizeLayout($validated);
        $settings->save();

        return response()->json($settings->fresh()->present());
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! Access::can($actor, 'login-page-crud')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:8192',
        ]);

        $file = $validated['image'];
        $name = (string) Str::uuid().'.'.$file->guessExtension();
        $path = app(ConfiguredStorage::class)->store($file, 'login-page', $name);

        $settings = LoginPageSetting::current();
        $image = $settings->addImage($path);
        $presented = $settings->fresh()->present();
        $created = collect($presented['images'])->firstWhere('id', $image['id']);

        return response()->json([
            'image' => $created,
            'layout' => $presented,
        ], 201);
    }

    public function destroyImage(Request $request, string $imageId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! Access::can($actor, 'login-page-crud')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $settings = LoginPageSetting::current();
        $settings->removeImage($imageId);

        return response()->json($settings->fresh()->present());
    }
}
