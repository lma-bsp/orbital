import { UserAgent, Registerer, RegistererState, Inviter, SessionState } from 'sip.js';

class SipPhone {
    constructor() {
        this.userAgent = null;
        this.registerer = null;
        this.session = null;
        this.state = 'idle'; // idle, connecting, ringing, in-call, incoming, ended, error
        this.onStateChange = null;
        this.remoteAudio = null;
        this.muted = false;
        this.held = false;
    }

    async connect(config) {
        const { wsUrl, domain, username, password } = config;

        const uri = UserAgent.makeURI(`sip:${username}@${domain}`);
        if (!uri) {
            this._setState('error', 'Invalid SIP URI');
            return;
        }

        const transportOptions = {
            server: wsUrl,
        };

        const userAgentOptions = {
            authorizationUsername: username,
            authorizationPassword: password,
            // Contact user = the extension. Asterisk only applies the
            // Path the SIP edge records (to reach this browser) when the
            // contact's user matches the AOR; SIP.js otherwise picks a
            // random one.
            contactName: username,
            transportOptions,
            uri,
            sessionDescriptionHandlerFactoryOptions: {
                peerConnectionConfiguration: {
                    iceServers: [{ urls: 'stun:stun.l.google.com:19302' }],
                },
            },
            delegate: {
                onInvite: (invitation) => this._handleIncoming(invitation),
                onDisconnect: (error) => {
                    if (error) {
                        this._setState('error', 'Disconnected');
                    }
                },
            },
        };

        this.userAgent = new UserAgent(userAgentOptions);

        try {
            await this.userAgent.start();

            // Ready only once the server accepts the login. register()
            // resolves when the REGISTER is sent, not when it succeeds.
            this.registerer = new Registerer(this.userAgent);
            this.registerer.stateChange.addListener((state) => {
                if (state === RegistererState.Registered && this.state === 'error') {
                    this._setState('idle', 'Registered');
                } else if (state === RegistererState.Unregistered && this.state === 'idle') {
                    this._setState('error', 'Logged out');
                }
            });
            this._setState('error', 'Logging in…');
            await this.registerer.register({
                requestDelegate: {
                    onReject: (response) => {
                        this._setState('error', `Login refused (${response.message.statusCode})`);
                    },
                },
            });
        } catch (error) {
            this._setState('error', error.message || 'Connection failed');
        }
    }

    _handleIncoming(invitation) {
        // One SIP session at a time: turn away a second INVITE while a
        // call is up or already ringing. This check has to come before
        // this.session is replaced, or the live call's handle is lost.
        if (this.session && ['in-call', 'incoming', 'connecting', 'ringing'].includes(this.state)) {
            invitation.reject();
            return;
        }

        this.session = invitation;
        const info = this._callInfo(invitation);
        this._setState('incoming', info.from || 'Unknown');
        this._emitCall('incoming', info);

        invitation.stateChange.addListener((state) => {
            switch (state) {
                case SessionState.Established:
                    this._setState('in-call');
                    this._setupAudio(invitation);
                    this._emitCall('answered', info);
                    break;
                case SessionState.Terminated:
                    this._emitCall('ended', info);
                    if (this.session === invitation) {
                        this._setState('idle');
                        this._cleanupAudio();
                        this.session = null;
                    }
                    break;
            }
        });
    }

    /**
     * Who is calling and which number they dialled. The dialled number
     * comes from X-Orbital-DID when the dialplan adds it.
     */
    _callInfo(invitation) {
        const request = invitation.request;
        return {
            callId: request?.callId || null,
            from: invitation.remoteIdentity?.uri?.user || null,
            fromName: invitation.remoteIdentity?.displayName || null,
            did: request?.getHeader?.('X-Orbital-DID') || null,
            // Set on test calls placed from the admin trial page.
            testCall: request?.getHeader?.('X-Orbital-Test-Call') || null,
        };
    }

    /** Tell the page about a call: incoming, answered, ended. */
    _emitCall(phase, info) {
        window.dispatchEvent(new CustomEvent('orbital-call', {
            detail: { phase, ...info },
        }));
    }

    async answer() {
        if (this.session && this.state === 'incoming') {
            await this.session.accept({
                sessionDescriptionHandlerOptions: {
                    constraints: { audio: true, video: false },
                },
                sessionDescriptionHandlerModifiers: [this._forceCodec('PCMU')],
            });
        }
    }

    reject() {
        if (this.session && this.state === 'incoming') {
            this.session.reject();
            this.session = null;
            this._setState('idle');
        }
    }

    async call(extension, domain) {
        if (!this.userAgent) {
            this._setState('error', 'Not connected');
            return;
        }

        const target = UserAgent.makeURI(`sip:${extension}@${domain}`);
        if (!target) {
            this._setState('error', 'Invalid extension');
            return;
        }

        this._setState('connecting');

        const inviter = new Inviter(this.userAgent, target, {
            sessionDescriptionHandlerOptions: {
                constraints: { audio: true, video: false },
            },
            sessionDescriptionHandlerModifiersReInvite: [this._forceCodec('PCMU')],
            sessionDescriptionHandlerModifiers: [this._forceCodec('PCMU')],
        });

        inviter.stateChange.addListener((state) => {
            switch (state) {
                case SessionState.Establishing:
                    this._setState('ringing');
                    break;
                case SessionState.Established:
                    this._setState('in-call');
                    this._setupAudio(inviter);
                    break;
                case SessionState.Terminated:
                    this._setState('idle');
                    this._cleanupAudio();
                    this.session = null;
                    break;
            }
        });

        try {
            await inviter.invite();
            this.session = inviter;
        } catch (error) {
            this._setState('error', error.message || 'Call failed');
        }
    }

    hangup() {
        if (this.session) {
            switch (this.session.state) {
                case SessionState.Established:
                    this.session.bye();
                    break;
                case SessionState.Establishing:
                case SessionState.Initial:
                    if (this.session.cancel) {
                        this.session.cancel();
                    } else if (this.session.reject) {
                        this.session.reject();
                    }
                    break;
                default:
                    break;
            }
            this.session = null;
        }
        this.muted = false;
        this.held = false;
        this._setState('idle');
        this._cleanupAudio();
    }

    toggleMute() {
        if (!this.session) return false;

        const sdh = this.session.sessionDescriptionHandler;
        if (!sdh) return false;

        const pc = sdh.peerConnection;
        if (!pc) return false;

        const senders = pc.getSenders();
        const audioSender = senders.find(s => s.track && s.track.kind === 'audio');

        if (audioSender && audioSender.track) {
            audioSender.track.enabled = !audioSender.track.enabled;
            this.muted = !audioSender.track.enabled;
            return this.muted;
        }

        return false;
    }

    async toggleHold() {
        if (!this.session || this.session.state !== SessionState.Established) return false;

        try {
            if (this.held) {
                // Unhold — re-invite with sendrecv
                const sdh = this.session.sessionDescriptionHandler;
                if (sdh && sdh.peerConnection) {
                    sdh.peerConnection.getSenders().forEach(sender => {
                        if (sender.track) sender.track.enabled = true;
                    });
                }
                this.held = false;
            } else {
                // Hold — stop sending audio
                const sdh = this.session.sessionDescriptionHandler;
                if (sdh && sdh.peerConnection) {
                    sdh.peerConnection.getSenders().forEach(sender => {
                        if (sender.track) sender.track.enabled = false;
                    });
                }
                this.held = true;
            }
            return this.held;
        } catch (e) {
            console.error('Hold toggle failed:', e);
            return this.held;
        }
    }

    sendDTMF(tone) {
        if (!this.session || this.session.state !== SessionState.Established) return;

        const sdh = this.session.sessionDescriptionHandler;
        if (sdh && sdh.peerConnection) {
            const senders = sdh.peerConnection.getSenders();
            const audioSender = senders.find(s => s.track && s.track.kind === 'audio');
            if (audioSender && audioSender.dtmf) {
                audioSender.dtmf.insertDTMF(tone, 100, 70);
            }
        }
    }

    async blindTransfer(extension, domain) {
        if (!this.session || this.session.state !== SessionState.Established) return false;

        const target = UserAgent.makeURI(`sip:${extension}@${domain}`);
        if (!target) return false;

        try {
            await this.session.refer(target);
            this.hangup();
            return true;
        } catch (e) {
            console.error('Blind transfer failed:', e);
            return false;
        }
    }

    _setupAudio(session) {
        const sdh = session.sessionDescriptionHandler;
        if (!sdh) return;

        const pc = sdh.peerConnection;
        if (!pc) return;

        if (!this.remoteAudio) {
            this.remoteAudio = document.createElement('audio');
            this.remoteAudio.id = 'sip-remote-audio';
            this.remoteAudio.autoplay = true;
            document.body.appendChild(this.remoteAudio);
        }

        const remoteStream = new MediaStream();
        pc.getReceivers().forEach(receiver => {
            if (receiver.track) {
                remoteStream.addTrack(receiver.track);
            }
        });

        this.remoteAudio.srcObject = remoteStream;
    }

    _cleanupAudio() {
        if (this.remoteAudio) {
            this.remoteAudio.srcObject = null;
            this.remoteAudio.remove();
            this.remoteAudio = null;
        }
    }

    /**
     * SDP modifier that strips all audio codecs except the specified one.
     * Forces PCMU (G.711 ulaw) so Asterisk can bridge without an opus transcoder.
     */
    _forceCodec(codecName) {
        return (description) => {
            const sdp = description.sdp;
            if (!sdp) return description;

            const lines = sdp.split('\r\n');
            const result = [];
            let inAudio = false;
            let audioPayloads = [];
            let keepPayloads = new Set();
            const codecPattern = new RegExp(`a=rtpmap:(\\d+)\\s+${codecName}/`, 'i');
            const telephoneEvent = /a=rtpmap:(\d+)\s+telephone-event\//i;

            // First pass: find payload numbers to keep
            for (const line of lines) {
                const codecMatch = line.match(codecPattern);
                if (codecMatch) keepPayloads.add(codecMatch[1]);
                const teMatch = line.match(telephoneEvent);
                if (teMatch) keepPayloads.add(teMatch[1]);
            }

            // Second pass: filter SDP
            for (const line of lines) {
                if (line.startsWith('m=audio')) {
                    inAudio = true;
                    const parts = line.split(' ');
                    // m=audio PORT PROTO PT1 PT2 ...
                    const filtered = parts.slice(0, 3).concat(
                        parts.slice(3).filter(pt => keepPayloads.has(pt))
                    );
                    result.push(filtered.join(' '));
                    continue;
                }
                if (line.startsWith('m=') && !line.startsWith('m=audio')) {
                    inAudio = false;
                }
                if (inAudio) {
                    const ptMatch = line.match(/^a=(?:rtpmap|fmtp|rtcp-fb):(\d+)/);
                    if (ptMatch && !keepPayloads.has(ptMatch[1])) continue;
                }
                result.push(line);
            }

            return { ...description, sdp: result.join('\r\n') };
        };
    }

    _setState(state, message = '') {
        this.state = state;
        if (this.onStateChange) {
            this.onStateChange(state, message);
        }
        // Dispatch custom event for Livewire integration
        window.dispatchEvent(new CustomEvent('sip-state-change', {
            detail: { state, message }
        }));
    }

    disconnect() {
        this.hangup();
        if (this.registerer) {
            this.registerer.unregister().catch(() => {});
            this.registerer = null;
        }
        if (this.userAgent) {
            this.userAgent.stop();
            this.userAgent = null;
        }
        this._setState('idle');
    }
}

// Export as global for Alpine.js usage
window.SipPhone = SipPhone;
