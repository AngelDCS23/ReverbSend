<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

new class extends Component {
    public $role = null;
    public $status = 'idle';
    public $pairingCode = null;
    public $inputCode = '';
    public $errorMessage = '';

    public function prepareTransfer($name, $size, $type, $socketId)
    {
        if (!$socketId) {
            $this->errorMessage = 'Conectando al servidor... Espera un segundo.';
            return;
        }
        $this->errorMessage = '';
        $this->pairingCode = strtoupper(Str::random(8));
        $this->role = 'sender';
        $this->status = 'waiting';

        Redis::setex('pairing_code:' . $this->pairingCode, 600, $socketId);
        $this->dispatch('code-generated', code: $this->pairingCode);
    }

    public function connectToCode()
    {
        $this->errorMessage = '';
        $code = strtoupper(trim($this->inputCode));

        if (strlen($code) !== 8 || !Redis::get('pairing_code:' . $code)) {
            $this->errorMessage = 'Código inválido o expirado.';
            return;
        }

        $this->role = 'receiver';
        $this->status = 'connecting';
        $this->pairingCode = $code;

        $this->dispatch('joined-channel', code: $code);
    }

    public function sendSignalData($signalData)
    {
        if ($this->pairingCode) {
            \App\Events\PeerConnected::dispatch($this->pairingCode, [
                'type' => 'signal',
                'signal' => $signalData,
                'senderRole' => $this->role
            ]);
        }
    }
};
?>

<div class="max-w-5xl mx-auto w-full px-4 flex-1 grid md:grid-cols-2 gap-8 pb-20 mt-8" x-data="{ 
        socketId: null,
        pc: null,
        dc: null,
        receivedChunks: [],
        receivedSize: 0,
        isP2POpen: false,
        
        transferFileName: '',
        transferFileType: 'application/octet-stream', 
        transferFileSize: 1, 
        
        channelJoined: false,
        iceCandidateQueue: [],
        candidateBatch: [],
        candidateTimeout: null,

        localProgress: 0,
        uiStatus: 'idle',
        lastUiUpdateSize: 0,
        
        signalQueue: [],
        isProcessingSignal: false,

        fileStream: null,
        fileToSend: null,
        writeQueue: Promise.resolve(),

        init() {
            setInterval(() => { 
                if (window.Echo && window.Echo.socketId()) this.socketId = window.Echo.socketId(); 
            }, 500);

            this.$wire.on('code-generated', ({code}) => { this.uiStatus = 'waiting'; this.subscribeToChannel(code); });
            this.$wire.on('joined-channel', ({code}) => { this.uiStatus = 'connecting'; this.subscribeToChannel(code); });
        },

        resetTransfer() {
            if (this.pc) { this.pc.close(); this.pc = null; }
            if (this.dc) { this.dc.close(); this.dc = null; }
            if (this.channelJoined && this.$wire.pairingCode) {
                window.Echo.leave('transfer.' + this.$wire.pairingCode);
            }
            
            this.receivedChunks = [];
            this.receivedSize = 0;
            this.isP2POpen = false;
            this.channelJoined = false;
            this.iceCandidateQueue = [];
            this.candidateBatch = [];
            clearTimeout(this.candidateTimeout);
            
            this.localProgress = 0;
            this.uiStatus = 'idle';
            this.lastUiUpdateSize = 0;
            this.signalQueue = [];
            this.isProcessingSignal = false;
            
            this.fileStream = null;
            this.fileToSend = null;
            this.writeQueue = Promise.resolve();
        },

        handleFileSelect(event) {
            const file = event.target.files[0];
            if (file) {
                this.resetTransfer(); 
                this.transferFileName = file.name;
                this.transferFileSize = file.size;
                this.transferFileType = file.type;
                this.$wire.prepareTransfer(file.name, file.size, file.type, this.socketId);
            }
        },

        async queueSignal(data) {
            this.signalQueue.push(data);
            if (!this.isProcessingSignal) {
                this.isProcessingSignal = true;
                while(this.signalQueue.length > 0) {
                    const signal = this.signalQueue.shift();
                    try {
                        await this.$wire.sendSignalData(signal);
                    } catch(e) { console.error('Error de red al enviar senal', e); }
                }
                this.isProcessingSignal = false;
            }
        },

        subscribeToChannel(code) {
            if (this.channelJoined) return;
            this.channelJoined = true;

            window.Echo.channel('transfer.' + code).listen('PeerConnected', async (e) => {
                if (e.data && e.data.senderRole === this.$wire.role) return;

                let payload = e.data;
                if (payload.type === 'signal') {
                    payload = payload.signal;
                }

                await this.handleSignal(payload);
            });

            // LA MAGIA: Si soy el receptor, me suscribo y espero 1.5s antes de avisar
            // Esto asegura que nunca haya sordera a la Oferta
            if (this.$wire.role === 'receiver') {
                setTimeout(() => {
                    this.queueSignal({ type: 'connected' });
                }, 1500);
            }
        },

        async initWebRTC() {
            const config = { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] };
            this.pc = new RTCPeerConnection(config);

            if (this.$wire.role === 'sender') {
                // 1. Añadimos ordered: true para asegurar que los trozos del archivo lleguen en estricto orden
                this.dc = this.pc.createDataChannel('fileTransfer', { ordered: true });
                // 2. Subimos el umbral del buffer a 4MB
                this.dc.bufferedAmountLowThreshold = 1024 * 1024 * 4; 
                
                this.setupDataChannel();
            } else {
                this.pc.ondatachannel = (event) => {
                    this.dc = event.channel;
                    // Aplicamos el mismo buffer al receptor
                    this.dc.bufferedAmountLowThreshold = 1024 * 1024 * 4;
                    
                    this.setupDataChannel();
                };
            }

            this.pc.onicecandidate = (e) => {
                if (e.candidate) {
                    this.candidateBatch.push(e.candidate);
                    clearTimeout(this.candidateTimeout);
                    this.candidateTimeout = setTimeout(() => {
                        this.queueSignal({ type: 'candidates', candidates: this.candidateBatch });
                        this.candidateBatch = [];
                    }, 300);
                }
            };
        },

        setupDataChannel() {
            this.dc.binaryType = 'arraybuffer';
            this.dc.bufferedAmountLowThreshold = 1024 * 1024 * 2; 

            this.dc.onopen = () => { 
                console.log('TUNEL P2P ABIERTO Y LISTO!'); 
                this.isP2POpen = true;
                this.uiStatus = 'connecting'; 
            };
            this.dc.onclose = () => { this.isP2POpen = false; };
            this.dc.onmessage = (e) => this.handleMessage(e);
        },

        async processIceQueue() {
            while (this.iceCandidateQueue.length > 0) {
                let candidate = this.iceCandidateQueue.shift();
                await this.pc.addIceCandidate(new RTCIceCandidate(candidate)).catch(e => console.error(e));
            }
        },

        async handleSignal(signal) {
            if (!this.pc) await this.initWebRTC();
            
            try {
                if (signal.type === 'connected') {
                    this.uiStatus = 'connecting';
                    // Cuando el receptor avisa que esta listo, el emisor empieza a trabajar
                    if (this.$wire.role === 'sender') {
                        this.queueSignal({ type: 'file-info', name: this.transferFileName, size: this.transferFileSize, fileType: this.transferFileType });
                        const offer = await this.pc.createOffer();
                        await this.pc.setLocalDescription(offer);
                        this.queueSignal({ type: 'offer', offer: offer });
                    }
                } else if (signal.type === 'file-info') {
                    this.transferFileName = signal.name;
                    this.transferFileSize = signal.size || 1;
                    this.transferFileType = signal.fileType || 'application/octet-stream';
                } else if (signal.type === 'offer') {
                    if (this.pc.signalingState !== 'stable') return; 
                    await this.pc.setRemoteDescription(new RTCSessionDescription(signal.offer));
                    await this.processIceQueue();
                    const answer = await this.pc.createAnswer();
                    await this.pc.setLocalDescription(answer);
                    this.queueSignal({ type: 'answer', answer: answer });
                } else if (signal.type === 'answer') {
                    if (this.pc.signalingState === 'have-local-offer') {
                        await this.pc.setRemoteDescription(new RTCSessionDescription(signal.answer));
                        await this.processIceQueue();
                    }
                } else if (signal.type === 'candidates') {
                    for (let c of signal.candidates) {
                        if (this.pc.remoteDescription) {
                            await this.pc.addIceCandidate(new RTCIceCandidate(c)).catch(e => console.error(e));
                        } else {
                            this.iceCandidateQueue.push(c);
                        }
                    }
                }
            } catch (error) { console.error('Error de WebRTC:', error); }
        },

        async sendFile(file) {
            if (!file || !this.dc) return;
            
            this.fileToSend = file;
            this.dc.send(JSON.stringify({
                __meta: true, name: file.name, type: file.type, size: file.size
            }));
            
            this.uiStatus = 'waiting_receiver';
        },

        async startSendingData() {
            this.uiStatus = 'sending';
            const file = this.fileToSend;
            const chunkSize = 131072; // 128KB: El punto dulce de velocidad
            let offset = 0;
            let lastUpdateOffset = 0;

            // Pre-leemos el primer trozo
            while (offset < file.size) {
                // Si el buffer está lleno, esperamos de forma más eficiente
                if (this.dc.bufferedAmount > this.dc.bufferedAmountLowThreshold) {
                    await new Promise(resolve => {
                        const checkBuffer = () => {
                            if (this.dc.bufferedAmount <= this.dc.bufferedAmountLowThreshold) {
                                resolve();
                            } else {
                                // Usamos un micro-timeout para no bloquear el procesador
                                setTimeout(checkBuffer, 1);
                            }
                        };
                        checkBuffer();
                    });
                }

                const slice = file.slice(offset, offset + chunkSize);
                const buffer = await slice.arrayBuffer();

                try {
                    this.dc.send(buffer);
                    offset += buffer.byteLength;

                    // Actualizamos la UI solo cada 5MB para no robarle potencia al envío
                    if (offset - lastUpdateOffset >= 5242880 || offset >= file.size) {
                        this.localProgress = Math.min(100, Math.round((offset / file.size) * 100));
                        lastUpdateOffset = offset;
                    }
                } catch (err) {
                    console.error('Error:', err);
                    break;
                }
            }
            this.dc.send('END_OF_FILE');
        },

        acceptAndSaveFile() {
            this.fileStream = streamSaver.createWriteStream(this.transferFileName, {
                size: this.transferFileSize
            });
            this.writer = this.fileStream.getWriter(); // Obtenemos el bolígrafo para escribir
            
            this.uiStatus = 'receiving';
            this.dc.send('RECEIVER_READY');
        },

        async handleMessage(event) {
            if (typeof event.data === 'string') {
                if (event.data === 'RECEIVER_READY') {
                    this.startSendingData();
                    return;
                }

                if (event.data === 'END_OF_FILE') {
                    if (this.writer) {
                        await this.writer.close(); // Cerramos el archivo (¡Terminado!)
                        this.writer = null;
                    }
                    this.localProgress = 100;
                    this.uiStatus = 'completed';
                    return; 
                } else {
                    try {
                        let parsed = JSON.parse(event.data);
                        if (parsed.__meta) {
                            this.transferFileName = parsed.name;
                            this.transferFileType = parsed.type;
                            this.transferFileSize = parsed.size || 1; 
                            this.uiStatus = 'file_offered'; 
                        }
                    } catch (e) {}
                }
                return; 
            }
            
            // Si llegan datos binarios, los escribimos directamente en el disco
            if (this.writer) {
                // Convertimos el paquete a un formato que el escritor entienda
                await this.writer.write(new Uint8Array(event.data));
                
                this.receivedSize += event.data.byteLength;
                
                if (this.receivedSize - this.lastUiUpdateSize >= 1048576 || this.receivedSize >= this.transferFileSize) {
                    this.localProgress = Math.min(100, Math.round((this.receivedSize / this.transferFileSize) * 100));
                    this.lastUiUpdateSize = this.receivedSize;
                }
            }
        }

        

        
    }">

    {{-- PANEL IZQUIERDO --}}
    <div class="bg-[#0a0a0a] rounded-2xl border border-white/5 p-8 flex flex-col items-center justify-center relative overflow-hidden group transition-all"
        :class="$wire.role === 'receiver' ? 'opacity-50 pointer-events-none grayscale' : ''">
        <div
            class="absolute inset-8 border-2 border-dashed border-red-900/50 rounded-xl bg-red-950/10 transition-all group-hover:bg-red-950/20 group-hover:border-red-800/80">
        </div>
        <div class="relative z-10 flex flex-col items-center text-center w-full">

            <input type="file" class="hidden" x-ref="fileInput" @change="handleFileSelect($event)">

            @if($role === 'sender' && $status !== 'idle')
                <div class="w-16 h-16 text-white rounded-full flex items-center justify-center mb-4 transition-colors"
                    :class="localProgress === 100 ? 'bg-green-500 shadow-[0_0_20px_rgba(34,197,94,0.5)]' : 'bg-red-600 animate-pulse shadow-[0_0_20px_rgba(220,38,38,0.5)]'">
                    <svg x-show="localProgress < 100" class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z">
                        </path>
                    </svg>
                    <svg x-show="localProgress === 100" style="display: none;" class="w-8 h-8" fill="none"
                        stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
                    </svg>
                </div>

                <h3 class="text-xl font-semibold mb-2 transition-colors"
                    :class="localProgress === 100 ? 'text-green-500' : 'text-red-500'"
                    x-text="localProgress === 100 ? '¡Archivo enviado!' : (uiStatus === 'connecting' || uiStatus === 'waiting_receiver' || uiStatus === 'sending' ? 'Conexión establecida' : 'Esperando conexión...')">
                </h3>

                <div class="bg-black border border-red-900/50 rounded-lg px-6 py-3 text-3xl font-bold tracking-widest text-white mb-2 shadow-inner"
                    :class="localProgress === 100 ? 'border-green-900/50 text-gray-400' : ''">
                    {{ $pairingCode }}
                </div>
                <p class="text-xs text-gray-500"
                    x-text="localProgress === 100 ? 'Transferencia finalizada' : 'Pásale este código al receptor'"></p>
            @else
                <div class="w-16 h-16 bg-red-950 text-red-500 rounded-full flex items-center justify-center mb-6">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                    </svg>
                </div>
                <h3 class="text-xl font-semibold mb-2">Selecciona tus archivos</h3>

                <button x-on:click="$refs.fileInput.click()"
                    class="bg-red-600 hover:bg-red-700 text-white font-medium px-6 py-3 rounded-lg transition shadow-lg">
                    <span x-text="transferFileName !== '' ? 'Cambiar archivo' : 'Elegir archivo'"></span>
                </button>

                @if($errorMessage && $role !== 'receiver' && $status === 'idle')
                    <p class="text-red-500 text-sm font-medium mt-4">{{ $errorMessage }}</p>
                @endif
            @endif

            <div x-show="transferFileName !== ''" style="display: none;"
                class="mt-6 p-4 bg-white/5 border border-white/10 rounded-xl flex items-center gap-4 w-full max-w-sm transition-all"
                :class="localProgress === 100 ? 'border-green-500/30 bg-green-950/20' : ''">
                <div class="w-10 h-10 rounded flex items-center justify-center transition-colors"
                    :class="localProgress === 100 ? 'bg-green-900/50 text-green-400' : 'bg-red-950 text-red-500'">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z">
                        </path>
                    </svg>
                </div>
                <div class="text-left overflow-hidden w-full">
                    <p class="text-sm font-medium text-white truncate" x-text="transferFileName"></p>
                    <p class="text-xs text-gray-400" x-text="(transferFileSize / 1024 / 1024).toFixed(2) + ' MB'"></p>
                </div>
            </div>
        </div>
    </div>

    {{-- PANEL DERECHO --}}
    <div class="bg-[#0a0a0a] rounded-2xl border border-white/5 p-8 flex flex-col gap-8 transition-all"
        :class="($wire.role === 'sender' && $wire.status === 'idle') ? 'opacity-50 pointer-events-none grayscale' : ''">

        <div :class="$wire.role === 'sender' ? 'opacity-40 pointer-events-none' : ''">
            <h3 class="text-xl font-semibold mb-4 flex items-center gap-2">
                <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                </svg>
                Recibir archivo
            </h3>

            <form wire:submit="connectToCode" @submit="resetTransfer()" class="flex gap-3">
                <input type="text" wire:model="inputCode" maxlength="8" placeholder="CÓDIGO"
                    class="bg-black border border-white/10 rounded-lg px-4 py-3 w-full focus:border-red-500 text-white uppercase tracking-widest">
                <button type="submit"
                    class="bg-red-950 text-red-500 border border-red-900/50 px-6 py-3 rounded-lg transition hover:bg-red-900">Conectar</button>
            </form>
            @if($errorMessage && $role !== 'sender')
                <span class="text-red-500 text-sm font-medium mt-2 block">{{ $errorMessage }}</span>
            @endif
        </div>

        <div class="h-px bg-white/5"></div>

        <div class="{{ $status !== 'idle' ? '' : 'opacity-40' }}">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-3 h-3 rounded-full transition-colors"
                    :class="localProgress === 100 ? 'bg-green-500' : (uiStatus === 'connecting' || uiStatus === 'waiting_receiver' || uiStatus === 'sending' || uiStatus === 'file_offered' || uiStatus === 'receiving' ? 'bg-green-500 animate-pulse' : 'bg-red-500')">
                </div>
                <h3 class="text-lg font-semibold"
                    x-text="localProgress === 100 ? '¡Archivo recibido con éxito!' : (uiStatus === 'connecting' || uiStatus === 'waiting_receiver' || uiStatus === 'sending' || uiStatus === 'file_offered' || uiStatus === 'receiving' ? '¡Conexión establecida!' : 'Estado')">
                </h3>
            </div>

            <div class="bg-black border border-white/5 rounded-xl p-4 mb-6">
                <p class="text-[10px] text-gray-500 font-bold mb-2">TRANSFERENCIA</p>
                <div class="flex justify-between items-end mb-2">
                    <span class="text-2xl font-bold transition-colors"
                        :class="localProgress === 100 ? 'text-green-500' : 'text-white'"
                        x-text="localProgress + '%'">0%</span>

                    <span class="text-xs transition-colors"
                        :class="localProgress === 100 ? 'text-green-500' : 'text-red-500'"
                        x-text="localProgress === 100 ? 'Completado' : (isP2POpen ? 'P2P Activo' : 'Inactivo')">
                    </span>
                </div>
                <div class="w-full bg-gray-900 rounded-full h-2">
                    <div class="h-2 rounded-full transition-all duration-300"
                        :class="localProgress === 100 ? 'bg-green-500' : 'bg-red-600'"
                        :style="`width: ${localProgress}%`"></div>
                </div>

                {{-- BOTONES DEL EMISOR --}}
                @if($role === 'sender')
                    <div x-show="transferFileName !== '' && localProgress < 100" class="mt-6">

                        <button x-show="isP2POpen && uiStatus !== 'waiting_receiver' && uiStatus !== 'sending'" x-cloak
                            @click="sendFile($refs.fileInput.files[0])"
                            class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-4 rounded-xl transition shadow-[0_0_20px_rgba(220,38,38,0.4)]">
                            Iniciar Envío
                        </button>

                        <button x-show="uiStatus === 'waiting_receiver'" x-cloak disabled
                            class="w-full bg-yellow-900/50 text-yellow-500 font-bold py-4 rounded-xl flex items-center justify-center gap-2 cursor-not-allowed border border-yellow-900/30">
                            <svg class="w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                                </path>
                            </svg>
                            Esperando a que el receptor guarde...
                        </button>

                        <button x-show="uiStatus === 'sending'" x-cloak disabled
                            class="w-full bg-red-900/50 text-red-500 font-bold py-4 rounded-xl flex items-center justify-center gap-2 cursor-not-allowed border border-red-900/30">
                            <svg class="w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                                </path>
                            </svg>
                            Enviando...
                        </button>

                        <button x-show="!isP2POpen" x-cloak disabled
                            class="w-full bg-red-900/50 text-red-500 font-bold py-4 rounded-xl flex items-center justify-center gap-2 cursor-not-allowed border border-red-900/30">
                            <svg class="w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                                </path>
                            </svg>
                            Perforando túnel seguro...
                        </button>
                    </div>
                @endif

                {{-- BOTONES DEL RECEPTOR --}}
                @if($role === 'receiver')
                    <div x-show="uiStatus === 'file_offered' && localProgress < 100" x-cloak class="mt-6">
                        <button @click="acceptAndSaveFile()"
                            class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 rounded-xl transition shadow-[0_0_20px_rgba(34,197,94,0.4)]">
                            Elegir dónde guardar
                        </button>
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-2 text-[10px] text-gray-500 uppercase tracking-tighter">
                <svg x-show="localProgress === 100" style="display: none;" class="w-4 h-4 text-green-500" fill="none"
                    stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>

                <svg x-show="localProgress < 100" class="w-3 h-3" :class="isP2POpen ? 'animate-spin' : ''" fill="none"
                    stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                    </path>
                </svg>

                <span
                    x-text="localProgress === 100 ? 'Archivo escrito en el disco' : (isP2POpen ? 'Transmisión cifrada P2P Directa al Disco' : 'Esperando señalización...')">
                </span>
            </div>
        </div>
    </div>
</div>