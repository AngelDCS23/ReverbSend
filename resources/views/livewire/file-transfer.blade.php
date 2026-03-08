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
    public $fileName = null;
    public $fileSize = null;
    public $fileType = null;

    public function prepareTransfer($name, $size, $type, $socketId)
    {
        if (!$socketId) {
            $this->errorMessage = 'Conectando al servidor... Espera un segundo y vuelve a elegir el archivo.';
            return;
        }
        $this->errorMessage = '';

        $this->fileName = $name;
        $this->fileSize = $size;
        $this->fileType = $type;

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

        if (strlen($code) !== 8) {
            $this->errorMessage = 'El código debe tener 8 caracteres.';
            return;
        }

        if (!Redis::get('pairing_code:' . $code)) {
            $this->errorMessage = 'El código no existe o ha expirado.';
            return;
        }

        $this->role = 'receiver';
        $this->status = 'connecting';
        $this->pairingCode = $code;

        \App\Events\PeerConnected::dispatch($code, [
            'type' => 'connected',
            'senderRole' => 'receiver'
        ]);

        $this->dispatch('joined-channel', code: $code);
    }

    public function sendFileInfo()
    {
        if ($this->pairingCode) {
            \App\Events\PeerConnected::dispatch($this->pairingCode, [
                'type' => 'file-info',
                'name' => $this->fileName,
                'size' => $this->fileSize,
                'fileType' => $this->fileType,
                'senderRole' => $this->role
            ]);
        }
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

    public function peerJoined()
    {
        $this->status = 'connecting';
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
        
        transferFileName: 'archivo_descargado',
        transferFileType: 'application/octet-stream', 
        transferFileSize: 1, // Previene división por cero al inicio
        
        channelJoined: false,
        iceCandidateQueue: [],
        candidateBatch: [],
        candidateTimeout: null,

        localProgress: 0,

        init() {
            const checkConnection = setInterval(() => { 
                if (window.Echo && window.Echo.socketId()) {
                    this.socketId = window.Echo.socketId(); 
                    clearInterval(checkConnection);
                }
            }, 500);

            this.$wire.on('code-generated', ({code}) => this.subscribeToChannel(code));
            this.$wire.on('joined-channel', ({code}) => this.subscribeToChannel(code));
        },

        handleFileSelect(event) {
            const file = event.target.files[0];
            if (file) {
                this.$wire.prepareTransfer(file.name, file.size, file.type, this.socketId);
            }
        },

        subscribeToChannel(code) {
            if (this.channelJoined) return;
            this.channelJoined = true;

            window.Echo.channel('transfer.' + code).listen('PeerConnected', async (e) => {
                if (e.data && e.data.senderRole === this.$wire.role) return;

                if (e.data && e.data.type === 'signal') {
                    await this.handleSignal(e.data.signal);
                } else if (e.data && e.data.type === 'file-info') {
                    this.$wire.fileName = e.data.name; 
                    this.$wire.fileSize = e.data.size;
                    
                    this.transferFileName = e.data.name;
                    this.transferFileType = e.data.fileType || 'application/octet-stream';
                    this.transferFileSize = e.data.size || 1;
                    
                    if (!this.pc) await this.initWebRTC();
                } else if (e.data && e.data.type === 'connected') {
                    this.$wire.peerJoined();
                    if (this.$wire.role === 'sender') {
                        this.$wire.sendFileInfo(); 
                        await this.initWebRTC();
                        const offer = await this.pc.createOffer();
                        await this.pc.setLocalDescription(offer);
                        this.sendSignal({ type: 'offer', offer: offer });
                    }
                }
            });
        },

        sendSignal(data) {
            this.$wire.sendSignalData(data);
        },

        async initWebRTC() {
            const config = { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] };
            this.pc = new RTCPeerConnection(config);

            if (this.$wire.role === 'sender') {
                this.dc = this.pc.createDataChannel('fileTransfer');
                this.setupDataChannel();
            } else {
                this.pc.ondatachannel = (event) => {
                    this.dc = event.channel;
                    this.setupDataChannel();
                };
            }

            this.pc.onicecandidate = (e) => {
                if (e.candidate) {
                    this.candidateBatch.push(e.candidate);
                    clearTimeout(this.candidateTimeout);
                    this.candidateTimeout = setTimeout(() => {
                        this.sendSignal({ type: 'candidates', candidates: this.candidateBatch });
                        this.candidateBatch = [];
                    }, 250);
                }
            };
        },

        setupDataChannel() {
            this.dc.binaryType = 'arraybuffer';
            this.dc.bufferedAmountLowThreshold = 1024 * 1024; 

            this.dc.onopen = () => { 
                console.log('¡TÚNEL P2P ABIERTO Y LISTO!'); 
                this.isP2POpen = true;
                this.$wire.status = 'connecting'; 
            };
            this.dc.onclose = () => {
                this.isP2POpen = false;
            };
            this.dc.onmessage = (e) => this.handleMessage(e);
        },

        processIceQueue() {
            while (this.iceCandidateQueue.length > 0) {
                let candidate = this.iceCandidateQueue.shift();
                this.pc.addIceCandidate(new RTCIceCandidate(candidate)).catch(e => console.error(e));
            }
        },

        async handleSignal(signal) {
            if (!this.pc) await this.initWebRTC();
            
            try {
                if (signal.type === 'offer') {
                    if (this.pc.signalingState !== 'stable') return; 
                    
                    await this.pc.setRemoteDescription(new RTCSessionDescription(signal.offer));
                    this.processIceQueue();
                    
                    const answer = await this.pc.createAnswer();
                    await this.pc.setLocalDescription(answer);
                    this.sendSignal({ type: 'answer', answer: answer });
                    
                } else if (signal.type === 'answer') {
                    if (this.pc.signalingState === 'have-local-offer') {
                        await this.pc.setRemoteDescription(new RTCSessionDescription(signal.answer));
                        this.processIceQueue();
                    }
                } else if (signal.type === 'candidates') {
                    signal.candidates.forEach(async (c) => {
                        if (this.pc.remoteDescription) {
                            await this.pc.addIceCandidate(new RTCIceCandidate(c)).catch(e => console.error(e));
                        } else {
                            this.iceCandidateQueue.push(c);
                        }
                    });
                }
            } catch (error) {
                console.error('Error de WebRTC:', error);
            }
        },

        async sendFile(file) {
            if (!file || !this.dc) return;
            if (this.dc.readyState !== 'open') {
                alert('El túnel aún se está estableciendo. Espera un momento.');
                return;
            }

            // Enviamos el Paquete Espía con toda la info
            this.dc.send(JSON.stringify({
                __meta: true,
                name: file.name,
                type: file.type,
                size: file.size
            }));
            
            const chunkSize = 16384; 
            const reader = new FileReader();
            let offset = 0;

            const readNext = () => {
                const slice = file.slice(offset, offset + chunkSize);
                reader.readAsArrayBuffer(slice);
            };

            reader.onload = (e) => {
                try {
                    this.dc.send(e.target.result);
                    offset += e.target.result.byteLength;
                    
                    let newProgress = Math.round((offset / file.size) * 100);
                    if (newProgress !== this.localProgress) {
                        this.localProgress = newProgress;
                    }

                    if (offset < file.size) {
                        if (this.dc.bufferedAmount > this.dc.bufferedAmountLowThreshold) {
                            this.dc.onbufferedamountlow = () => {
                                this.dc.onbufferedamountlow = null; 
                                readNext(); 
                            };
                        } else {
                            readNext();
                        }
                    } else {
                        this.dc.send('END_OF_FILE');
                    }
                } catch (err) {
                    console.error('Error enviando datos:', err);
                }
            };

            readNext(); 
        },

        handleMessage(event) {
            if (typeof event.data === 'string') {
                if (event.data === 'END_OF_FILE') {
                    const blob = new Blob(this.receivedChunks, { type: this.transferFileType });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    
                    a.download = this.transferFileName;
                    
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    
                    this.receivedChunks = [];
                    this.receivedSize = 0;
                    this.localProgress = 100;
                    
                    setTimeout(() => URL.revokeObjectURL(url), 1000);
                } else {
                    try {
                        let parsed = JSON.parse(event.data);
                        if (parsed.__meta) {
                            this.transferFileName = parsed.name;
                            this.transferFileType = parsed.type;
                            this.transferFileSize = parsed.size || 1; // Guardamos el peso real
                        }
                    } catch (e) {}
                }
                return; 
            }
            
            this.receivedChunks.push(event.data);
            this.receivedSize += event.data.byteLength;
            
            // Usamos la variable local y segura para calcular
            let total = this.transferFileSize;
            let newProgress = Math.round((this.receivedSize / total) * 100);
            if (newProgress !== this.localProgress) {
                this.localProgress = newProgress;
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
                <div
                    class="w-16 h-16 bg-red-600 text-white rounded-full flex items-center justify-center mb-4 animate-pulse shadow-[0_0_20px_rgba(220,38,38,0.5)]">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z">
                        </path>
                    </svg>
                </div>
                <h3 class="text-xl font-semibold mb-2 text-red-500">
                    {{ $status === 'connecting' ? 'Conexión establecida' : 'Esperando conexión...' }}
                </h3>
                <div
                    class="bg-black border border-red-900/50 rounded-lg px-6 py-3 text-3xl font-bold tracking-widest text-white mb-2 shadow-inner">
                    {{ $pairingCode }}
                </div>
                <p class="text-xs text-gray-500">Pásale este código al receptor</p>
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
                    {{ $fileName ? 'Cambiar archivo' : 'Elegir archivo' }}
                </button>

                @if($errorMessage && $role !== 'receiver' && $status === 'idle')
                    <p class="text-red-500 text-sm font-medium mt-4">{{ $errorMessage }}</p>
                @endif
            @endif

            @if($fileName)
                <div class="mt-6 p-4 bg-white/5 border border-white/10 rounded-xl flex items-center gap-4 w-full max-w-sm">
                    <div class="w-10 h-10 bg-red-950 text-red-500 rounded flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z">
                            </path>
                        </svg>
                    </div>
                    <div class="text-left overflow-hidden">
                        <p class="text-sm font-medium text-white truncate">{{ $fileName }}</p>
                        <p class="text-xs text-gray-400">{{ number_format($fileSize / 1024 / 1024, 2) }} MB</p>
                    </div>
                </div>
            @endif
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
            <form wire:submit="connectToCode" class="flex gap-3">
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
                <div
                    class="w-3 h-3 rounded-full {{ $status === 'connecting' ? 'bg-green-500 animate-pulse' : 'bg-red-500' }}">
                </div>
                <h3 class="text-lg font-semibold">{{ $status === 'connecting' ? '¡Conexión establecida!' : 'Estado' }}
                </h3>
            </div>

            <div class="bg-black border border-white/5 rounded-xl p-4 mb-6">
                <p class="text-[10px] text-gray-500 font-bold mb-2">TRANSFERENCIA</p>
                <div class="flex justify-between items-end mb-2">
                    <span class="text-2xl font-bold text-white" x-text="localProgress + '%'">0%</span>
                    <span class="text-xs text-red-500">{{ $status === 'connecting' ? 'P2P Activo' : 'Inactivo' }}</span>
                </div>
                <div class="w-full bg-gray-900 rounded-full h-2">
                    <div class="bg-red-600 h-2 rounded-full transition-all duration-300"
                        :style="`width: ${localProgress}%`"></div>
                </div>

                @if($role === 'sender' && $status === 'connecting' && $fileName)
                    <div x-show="localProgress < 100">
                        <button x-show="isP2POpen" x-cloak @click="sendFile($refs.fileInput.files[0])"
                            class="mt-6 w-full bg-red-600 hover:bg-red-700 text-white font-bold py-4 rounded-xl transition shadow-[0_0_20px_rgba(220,38,38,0.4)]">
                            Iniciar Envío Real
                        </button>

                        <button x-show="!isP2POpen" x-cloak disabled
                            class="mt-6 w-full bg-red-900/50 text-red-500 font-bold py-4 rounded-xl flex items-center justify-center gap-2 cursor-not-allowed border border-red-900/30">
                            <svg class="w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                                </path>
                            </svg>
                            Perforando túnel seguro...
                        </button>
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-2 text-[10px] text-gray-500 uppercase tracking-tighter">
                <svg class="w-3 h-3 {{ $status === 'connecting' ? 'animate-spin' : '' }}" fill="none"
                    stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                    </path>
                </svg>
                {{ $status === 'connecting' ? 'Transmisión cifrada punto a punto' : 'Esperando señalización...' }}
            </div>
        </div>
    </div>
</div>