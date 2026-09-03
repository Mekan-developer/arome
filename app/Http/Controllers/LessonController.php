<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * База знаний — готовый статический сайт (страницы, стили, обложки и 79 mp3 с
 * озвучкой уроков). Лежит он в resources/, а не в public/: всё, что попадает в
 * public/, nginx раздаёт мимо PHP, то есть любой без входа в панель скачал бы
 * обучение по прямой ссылке. Здесь же файл сначала проходит через сессию.
 */
class LessonController extends Controller
{
    /**
     * Расширения, которые встречаются в материале. Всё остальное — 404: каталог
     * пополняют копированием папки, и белый список не даёт случайно уехавшему в
     * неё .php или .env стать доступным по ссылке.
     *
     * @var array<string, string>
     */
    private const TYPES = [
        'html' => 'text/html; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'mp3' => 'audio/mpeg',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
    ];

    /**
     * Ссылки внутри материала относительные («assets/base.css», «../audio/…»), а
     * браузер считает их от последнего сегмента адреса. По /lessons базой был бы
     * корень сайта и стили ушли бы в /assets, поэтому вход — редирект на сам файл.
     */
    public function index(): RedirectResponse
    {
        return redirect()->route('lessons.show', ['path' => 'index.html']);
    }

    public function show(string $path): BinaryFileResponse
    {
        $file = $this->resolve($path);

        return response()->file($file, [
            'Content-Type' => self::TYPES[mb_strtolower(pathinfo($file, PATHINFO_EXTENSION))],
            // private — иначе прокси между сервером и продавцом сложит у себя
            // страницу, отданную по чужой сессии.
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Путь из адреса — то, что прислал клиент, поэтому «..» и симлинк наружу
     * разворачиваются realpath-ом и сверяются с корнем каталога.
     */
    private function resolve(string $path): string
    {
        $root = realpath(resource_path('lessons'));
        $file = realpath($root.'/'.$path);

        abort_if($root === false || $file === false, 404);
        abort_unless(str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file), 404);
        abort_unless(isset(self::TYPES[mb_strtolower(pathinfo($file, PATHINFO_EXTENSION))]), 404);

        return $file;
    }
}
