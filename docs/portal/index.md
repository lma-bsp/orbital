# Client Portal

The portal is what your clients see. It is where the business you answer for
logs in to review the calls taken for them and the text conversations handled
on their behalf.

It is deliberately narrow. A client can see what was done for them and change
nothing about how it is done — they never configure telephony, never take
calls, and never see another client's data.

## Who gets access

Client contacts are created by an administrator on the client's **Users**
sub-page. Each one gets their own login; there is no shared account.

Access is scoped to a single client. A person who works for two of your clients
needs two logins, because the alternative — one account seeing two businesses'
callers — is not a boundary worth blurring.

## What a client sees

| Page | Shows | Needs |
|---|---|---|
| **Overview** | Calls today, recent call count, and a list of the latest activity | `portal.view_home` (the activity list also needs `portal.view_calls`) |
| [Call History](call-history.md) | Calls taken on their behalf, with transcripts and recordings where enabled | `portal.view_calls`; **Play** also needs `portal.view_recordings` |
| [Text Conversations](text-conversations.md) | SMS and MMS threads handled for them | `portal.view_messages` |
| **Security** | Their own two-factor enrolment, password, and active sessions | — |
| **About** | What version of Orbital this is, and where its source lives | — |

The Text Conversations item is hidden for clients with no text traffic. An
empty page for a product they do not use reads as something broken.

### Who sees which page

Each page is gated on the permission in the table above. A page a user's roles
do not grant is missing from their navigation and refuses a direct link. A user
without `portal.view_home` lands on the first page they can open instead of the
Overview; a user whose roles grant none of the pages sees a short "no access
yet" page telling them to ask their account's administrator.

Every invited contact gets `client_user`, which carries all four view
permissions, so out of the box everybody sees everything above. A client-side
administrator narrows that by editing roles (below) — for example a role
without `portal.view_recordings` for staff who should see call history but not
listen to calls.

The account's owner always keeps every page the client's
[permission ceiling](../admin/security.md#the-permission-ceiling) allows, even
if their own roles lose a permission. Nobody inside the client — the owner
included — gets a permission that is off the ceiling: take
`portal.view_recordings` off a client's ceiling and nobody at that client can
play recordings.

### Administration

Client-side administrators get a **Roles** page, for managing roles within
their own account.

**It is shown by default.** A freshly provisioned client's ceiling includes
`portal.manage_roles` and `portal.manage_users`, the client's first contact
gets `client_admin` (which holds the whole ceiling), and the account owner
always sees the page. Other contacts see it only if one of their roles carries
`portal.manage_roles`. The permissions a role can be given are capped by the
client's permission ceiling, so removing `portal.manage_roles` from the ceiling
hides the page from everyone at that client except its owner.

## Messages

> **Messages are not yet a page in the portal.** The intake record an operator
> or agent produces is stored against the client's account and worked from the
> operator side, and voicemails reach the client by email with the audio
> attached. A client-facing message list is the next significant piece of
> portal work. See the [Roadmap](../roadmap.md).
>
> Until then, a client reviews activity through Call History and Text
> Conversations, and receives voicemail by mail. Set that expectation during
> onboarding rather than letting them find it.

### Incomplete messages

Where a caller drops off part-way through an intake, the record is marked
incomplete rather than discarded — the platform captured a name and a number
but never got the reason. An incomplete record says what is missing, so it
reads as "caller hung up before giving a reason" rather than as a blank
somebody forgot to fill in.

Whether these are kept at all is a per-client setting, off by default. See
[Clients](../admin/clients.md).

## What clients cannot do

- **Reply to texts.** The portal is a view of what was said on their behalf,
  not a second place for the conversation to happen. Replies come from
  operators so the thread has one voice.
- **Change routing, queues, or agents.** That is the answering service's job.
- **Take their own calls.** Orbital is an answering service platform; the
  portal is a record of work done for them.
- **See other clients.** Every query is scoped to their account.

## Branding

The portal carries your branding rather than Orbital's — your name, logo, and
favicon, on the portal and on the shared login page every user hits before
being routed to their panel. Clients see the company they hired. Configured
under **Preferences → Portal Branding**; see
[Platform Settings](../admin/platform-settings.md).

## Two-factor

Two-factor applies to portal users as well as staff. Somebody with a client
login can read every call taken for that business, which is worth protecting
properly. The grace window can be set per client for accounts with less
technical staff. See [Security](../admin/security.md).

## See also

- [Users & Roles](../admin/users-roles.md) — creating client contacts
- [Clients](../admin/clients.md) — the account the portal is scoped to
