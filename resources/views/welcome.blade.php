<!DOCTYPE html>
<html lang="es" class="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/streamsaver@2.0.5/StreamSaver.min.js"></script>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <title>ReverbSend</title>

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
            <span class="text-xl font-bold tracking-tight">ReverbSend</span>
        </div>

    </nav>

    <header class="text-center pt-20 pb-12 px-4">
        <h1 class="text-4xl md:text-5xl font-bold mb-4 tracking-tight">
            Transferencia Directa P2P <span class="text-red-600">sin Intermediarios</span>
        </h1>
        <p class="text-gray-400 text-lg max-w-2xl mx-auto">
            La forma más privada de enviar archivos pesados: los datos viajan directamente de dispositivo a dispositivo,
            sin pasar por la nube.
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
            <h4 class="font-bold mb-2">Privacidad total</h4>
            <p class="text-sm text-gray-500">A diferencia de otras apps, tus archivos nunca se almacenan en internet. La
                transferencia es un túnel privado y directo entre tú y el destinatario.</p>
        </div>
        <div>
            <div class="w-10 h-10 mx-auto text-red-600 mb-4 flex justify-center items-center">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                </svg>
            </div>
            <h4 class="font-bold mb-2">Velocidad Máxima</h4>
            <p class="text-sm text-gray-500">Sin límites impuestos. La velocidad depende únicamente de tu conexión.</p>
        </div>
        <div>
            <div class="w-10 h-10 mx-auto text-red-600 mb-4 flex justify-center items-center">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <h4 class="font-bold mb-2">Seguridad P2P</h4>
            <p class="text-sm text-gray-500">Los datos viajan fragmentados y cifrados mediante el protocolo WebRTC.
                Nadie, ni siquiera nosotros, puede interceptar o ver lo que envias.</p>
        </div>
    </section>

    <footer
        class="border-t border-white/5 py-8 px-8 flex flex-col md:flex-row justify-between items-center text-xs text-gray-500 max-w-7xl mx-auto w-full">
        <div class="flex items-center gap-2 mb-4 md:mb-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
            </svg>
            © 2026 Ángel De Cara Salas
        </div>
    </footer>

</body>

</html>