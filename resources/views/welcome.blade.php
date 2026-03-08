<!DOCTYPE html>
<html lang="es" class="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FileShare - Envía archivos al instante</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>

<body class="bg-[#050505] text-white min-h-screen flex flex-col selection:bg-red-600 selection:text-white">

    <nav class="flex items-center justify-between px-8 py-6 border-b border-white/5">
        <div class="flex items-center gap-2">
            <div class="w-8 h-8 bg-red-600 rounded flex items-center justify-center text-white font-bold">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                </svg>
            </div>
            <span class="text-xl font-bold tracking-tight">FileShare</span>
        </div>
        <div class="hidden md:flex items-center gap-8 text-sm font-medium text-gray-300">
            <a href="#" class="hover:text-white transition">Transferencias</a>
            <a href="#" class="hover:text-white transition">Recientes</a>
            <a href="#" class="hover:text-white transition">Ayuda</a>
            <a href="#"
                class="bg-red-600 hover:bg-red-700 text-white px-5 py-2.5 rounded-full transition shadow-[0_0_15px_rgba(220,38,38,0.3)]">Mi
                Cuenta</a>
        </div>
    </nav>

    <header class="text-center pt-20 pb-12 px-4">
        <h1 class="text-4xl md:text-5xl font-bold mb-4 tracking-tight">
            Envía y recibe archivos <span class="text-red-600">al instante</span>
        </h1>
        <p class="text-gray-400 text-lg max-w-2xl mx-auto">
            La forma más rápida y segura de compartir documentos pesados<br>sin registros ni complicaciones.
        </p>
    </header>

    <livewire:file-transfer />

    <section
        class="border-t border-white/5 pt-16 pb-20 px-8 max-w-6xl mx-auto w-full grid grid-cols-1 md:grid-cols-3 gap-12 text-center mt-auto">
        <div>
            <div class="w-10 h-10 mx-auto text-red-600 mb-4 flex justify-center items-center">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z">
                    </path>
                </svg>
            </div>
            <h4 class="font-bold mb-2">Cifrado de extremo a extremo</h4>
            <p class="text-sm text-gray-500">Tus archivos están protegidos y solo el destinatario puede verlos.</p>
        </div>
        <div>
            <div class="w-10 h-10 mx-auto text-red-600 mb-4 flex justify-center items-center">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                </svg>
            </div>
            <h4 class="font-bold mb-2">Sin límites de velocidad</h4>
            <p class="text-sm text-gray-500">Transferencias ultrarrápidas optimizadas para archivos de gran tamaño.</p>
        </div>
        <div>
            <div class="w-10 h-10 mx-auto text-red-600 mb-4 flex justify-center items-center">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <h4 class="font-bold mb-2">Auto-eliminación</h4>
            <p class="text-sm text-gray-500">Los archivos se eliminan automáticamente después de su descarga o
                expiración.</p>
        </div>
    </section>

    <footer
        class="border-t border-white/5 py-8 px-8 flex flex-col md:flex-row justify-between items-center text-xs text-gray-500 max-w-7xl mx-auto w-full">
        <div class="flex items-center gap-2 mb-4 md:mb-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
            </svg>
            © 2024 FileShare Inc.
        </div>
        <div class="flex gap-6">
            <a href="#" class="hover:text-white transition">Términos</a>
            <a href="#" class="hover:text-white transition">Privacidad</a>
            <a href="#" class="hover:text-white transition">Contacto</a>
        </div>
    </footer>

</body>

</html>