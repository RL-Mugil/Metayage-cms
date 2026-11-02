# Server SSH access: connection notes (incident of 29 Oct 2026)

Droplet: `idpd-mypl` (DigitalOcean id 573718226, public IP 139.59.85.216, Tailscale name `idpd-do`).

## How to connect (use this)

```
ssh idpd-do          # via Tailscale, uses ~/.ssh/id_ed25519_idpd (see ~/.ssh/config)
```
Tailscale must be **running and signed in** on the PC first (`tailscale status` should list `idpd-do` as active).
If the Tailscale tray icon is missing: run `tailscale up`, open the login URL it holds (`tailscale status --json`, field `AuthURL`), sign in as the account that owns the tailnet (mugil2927@), approve the device.
Do **not** pick an exit node on the PC.

Public `ssh root@139.59.85.216` was closed on 22 Oct 2026 (Tailscale only) and must not be used.

## What happened

- `ssh root@139.59.85.216` began failing with "REMOTE HOST IDENTIFICATION HAS CHANGED".
- The key shown was ED25519 `SHA256:Qq8cvrEN28iPyxTIzZlf7WtLeVsLzWO3awRgJ9WZfy8` (and a different ECDSA and RSA key).
- The server's real key (checked from the DigitalOcean Recovery Console) is ED25519 `SHA256:K11sV0sbuxU/aWJOFJOBRekCXOqN49Q4B0N2QiWNXNU`, equal to the key saved in `known_hosts`. The server's keys never changed.
- With `journalctl -f | grep -i ssh` running on the server, an SSH attempt from the PC produced **no** log line, so the connection never reached the server's sshd. Something on the path answered port 22 with its own key.
- The same result came over the home route with the VPN off (traceroute showed only the router and ISP). The portal on 443 reached the real server normally, so only port 22 was affected.
- The DigitalOcean web console (Droplet Console) also failed ("All configured authentication methods failed"). The Recovery Console (Access tab, root password reset) worked.
- At the time Tailscale on the PC was stopped and the server had been removed from the tailnet. After re-adding both (server: `tailscale up --accept-dns=false --advertise-exit-node --hostname=idpd-do`; PC: `tailscale up` and sign-in), `ssh idpd-do` worked with no key warning.

## Possible root causes (not confirmed)

1. Something between the PC and the server answers port 22 (home router, ISP or office network setting, a transparent proxy or honeypot). Most consistent with the evidence.
2. Leftover Tailscale or VPN routing on the PC (an active exit node sends all traffic through another machine). Was off during the second test, so less likely.
3. A service in front of the droplet in the DigitalOcean network (unlikely; no evidence).

Cause of the Droplet Console failure was not established (likely the DigitalOcean droplet agent or SSH settings; the agent was active, SSH is socket-activated so `ssh.service` shows "inactive" when idle, which is normal).

## How to settle it next time

- From a different network (phone hotspot): `ssh-keyscan -t ed25519 139.59.85.216 | ssh-keygen -lf -`. If it prints the `K11sV0sbu...` key, the interference is in the home or office network.
- Never accept a changed host key before comparing with the server: in the Recovery Console run `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`.

## If locked out again

DigitalOcean panel > droplet > Access > Recovery Console (log in as root; use Reset root password if needed, the password is emailed and a change is forced at first login). Keep the new root password in a password manager, never in chat or in the repository.

## Follow-ups

- The server advertises itself as a Tailscale exit node (`--advertise-exit-node`); consider turning it off (other devices could route their internet through this production server).
- A Tailscale API key was pasted into a chat on 29 Oct 2026; revoke it (Tailscale admin > Settings > Keys).
- Profile pictures: two `users.avatar_url` rows pointed at `https://myipstrategy.com/...`; fixed to `https://login.myipstrategy.com/...` on 29 Oct 2026.
