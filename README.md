# Checkpoints Live v3

<p>
<!-- img align="right" height="400" src="https://example.com" alt="screenshot of the widget running at default settings"/ -->
This script is a <a href="https://www.xaseco.org/">XAseco</a> plug-in that
displays the players and spectators present on a server along with their
current checkpoint counts, checkpoint times, and spectator targets.  The UI
variant of the widget displayed to spectators renders clickable driver rows for
the convenience of quickly switching between drivers to spectate.
</p>

The "v3" in the name is a nod to the earlier Checkpoints Live Advanced
[plug-in](https://plugins.xaseco.org/info.php?id=166) by Lambda & b4card1, for
which this one is intended to be a drop-in replacement. The architecture of
this plug-in has been designed and its code has been written from scratch with
the goal of minimizing the number of `addCall` invocations performed over the
widget's lifetime while replicating most of the features of the predecessor,
and adding many more.

Credit also goes to Xerox for coming in late at night to the **ぎ
skidz.RPG.Trial.** server, describing the idea of clickable CP Live rows, and
causing [potato](https://github.com/join-red) to obsess over squeezing out
performance improvements thereby.  The authors also thank the many witting and
unwitting testers who came in while stress tests were taking place, and people
who otherwise expressed interest in the plug-in.

Table of Contents
=================

* [Checkpoints Live v3](#checkpoints-live-v3)
* [Table of Contents](#table-of-contents)
   * [Usage and performance](#usage-and-performance)
      * [Installation](#installation)
      * [Benchmarks](#benchmarks)
      * [Miscellaneous usage notes](#miscellaneous-usage-notes)
   * [Features](#features)
      * [User commands](#user-commands)
         * [color](#color)
         * [toggle](#toggle)
      * [MasterAdmin commands](#masteradmin-commands)
         * [refresh [&lt;int&gt;]](#refresh-int)
         * [rows [&lt;int&gt;]](#rows-int)
         * [strict [toggle]](#strict-toggle)
         * [leader [toggle]](#leader-toggle)
         * [specs [toggle]](#specs-toggle)
         * [specmarker [toggle]](#specmarker-toggle)
         * [spectarget [toggle]](#spectarget-toggle)
         * [disable](#disable)
         * [enable](#enable)
      * [Nerd commands](#nerd-commands)
         * [stats](#stats)
   * [License &amp; contributing](#license--contributing)
   * [Copyright](#copyright)

## Usage and performance

### Installation

0. Ensure that you are running PHP v7.3 (and that your XAseco installation is
   appropriately modernized for it).  Newer PHP versions may work but have
   not been tested.  Ones prior to v7.1 will not work.
1. Place the `plugin.cplive_v3.php` file inside the `./xaseco/plugins` directory.
2. Include this line in XAseco's `plugins.xml` at an appropriate location:
   ```
   <plugin>plugin.cplive_v3.php</plugin>
   ```
3. Read this `README.md` and the commentary to the `plugin.cplive_v3.php`
   configuration section.  Configure the plug-in as required.  Read the rest of
   the commentary.  If this is too difficult, ask an appropriately capable
   language model to perform these actions and explain them to you.[^cap]
4. Restart XAseco.

[^cap]: An appropriate level of capability is displayed by, for instance,
        Claude Opus 4.6 (either instant or thinking) or GPT-5.4 (not the instant
        variant) as of March 2026.  Less capable models such as contemporary
        Chinese ones may not help.

### Benchmarks

Some `addCall`/s statistics have been gathered during live-fire testing on
a busy server and during stress testing on a purpose-built checkpoint spam
track; both on decent VDS hardware.

| Scenario | Description | `addCall`/s | `addCall`/s, no-CSL[^nocsl] | Note |
| :-- | :-- | :--: | :--: | :-- |
| **Stress test #1** | A checkpoint spam track, purpose-built by [Falleos](https://github.com/Falleos) when an earlier one built by facecat was not nearly enough: 150 checkpoints (evenly spaced groups of blockmixed rings along a full booster road), 7–12 seconds long. 4–6 concurrently driving players and several spectators at a **100-millisecond** refresh interval. The Records Eyepiece plug-in's "nice mode" was enabled. | 11–12/s | 36–37/s | No noticeable lag. |
| **Stress test #2** | Same as above but the Records Eyepiece plug-in's **"nice mode" was disabled**. | - | - | Lag (from acceptable to heavy). |
| **skidz.Trial.Event.4 Lobby**  | The *ぎ skidz.RPG.Trial.Lobby* track: 12 seconds long, 11 evenly spaced checkpoints. 6–15 concurrently driving players and several spectators at a **50-millisecond** refresh interval.  The statistics were recorded after 15 minutes of playtime. The Records Eyepiece plug-in's "nice mode" was enabled.  | 4/s | 19.42/s | No noticeable lag. |
| **skidz.Trial.Event.4** #1  | A main event track: an easy-list-difficulty trial with the first few checkpoints roughly 10 seconds, 30 seconds, and a minute in.  A dozen competing drivers and about 120% as many spectators.  The observation was recorded after the first -- usually most intense load-wise -- 15 minutes of playtime, the leader at CP 16, at a **50-millisecond** refresh interval. The Records Eyepiece plug-in's "nice mode" was enabled. | 0.42/s | 4.16/s | No lag. |
| **skidz.Trial.Event.4** #2  | Same as above but the Records Eyepiece plug-in's "nice mode" was disabled, and the observation was recorded a bit later. | - | - | No lag. |
| **Regular play**  | Roughly a dozen players on the server, some drivers, some spectators, at a 500-millisecond refresh interval. No "nice mode." | ~0.05/s | - | No lag. |

[^nocsl]: Non-comma-separated logins equivalent.  See the explanation in the
          [stats](#stats) section.

**NB**: Since the exact `addCall`/s statistics depend on how many players are
actively taking checkpoints and how often, on which widget rendering variants
they are using, and on many other stochastic factors, and since the salience of
the lag depends on the server's hardware and co-hosted software, the numbers
and scenarios given above are only directionally correct.  They are provided to
establish some context in case an unforeseen performance issue comes up and to
avoid directing attention at unlikely culprits.

TL;DR: A 500-millisecond refresh interval should be fine for most practical
uses, and a 1-second refresh interval in Strict Mode should be enough for the
most paranoid server owner.

### Miscellaneous usage notes

If the [Records Eyepiece](https://plugins.xaseco.org/info.php?id=68) plug-in is
active, it is highly recommended also to enable its 'nice mode' feature when
the server is experiencing or is expected to experience a high level of
activity:

```
/eyeset forcenice true
```

The plug-in's performance with record panels displayed by plug-ins other than
Records Eyepiece -- such as FuFi Widgets -- was not tested by the authors.
Tested on PHP v7.3 running under GNU/Linux.

## Features

The manual below only concerns chat-based commands that are available in-game.
The settings which are intended to be only set once by the server owner are
accompanied by commentary in `plugin.cplive_v3.php`.  To name a few: the
widget's color scheme, `ALLOW_NICK_STYLE_TOGGLE`, the position of the widget on
the screen, and which function key may toggle the widget, if any.

The general format of a Checkpoints Live v3 chat-based command is as follows:

```
/cplive [param [arg]]
```

The in-game help window is displayed when the parameter is omitted, as well
as when an unsupported parameter is supplied.  Some of the parameters have
optional arguments: see below in more detail.

---
### User commands


#### color
```
/cplive color
```
Toggle between colored and monochrome player names according to the color
scheme set by the server's owner: a lesser-distraction mode.  This setting
may also be toggled by clicking the widget's title bar in the expanded state.

#### toggle
```
/cplive toggle
```
Collapse or expand the widget: a minimal-distraction mode.  This setting may
also be changed by clicking the cross icon in the widget's title bar (in the
expanded state) or the eye icon (in the collapsed state).

---
### MasterAdmin commands

#### refresh [\<int\>]
```
/cplive refresh 50
/cplive refresh 1000
/cplive refresh
```
Set the widget's global update interval, in milliseconds. By default the
plug-in allows to set values between 50 milliseconds and one hour, inclusive.
Prints the current value when used without an argument.

Setting this interval to `<int>` milliseconds does not mean that the widget
will periodically be dispatched to the players every global tick when `<int>`
milliseconds have passed; rather, a pending update is *allowed* to be
dispatched once the throttler expires.  An event that triggers a pending update
may be a taken checkpoint or a disconnected player.  If no such event occurs,
nothing at all is dispatched at the next global interval tick.

Some events -- such as connections and disconnections -- are configured to
trigger a dispatch regardless of the global interval so that stale clickable
rows are not displayed.  This is a "forced" dispatch.

#### rows [\<int\>]
```
/cplive rows 1
/cplive rows 20
/cplive rows
```
This setting controls the maximum number of players shown by the widget. The
plug-in allows to set values between one row and fifty rows, inclusive.  Prints
the current value when used without an argument.

#### strict [toggle]
```
/cplive strict toggle
/cplive strict
```
Enable or disable Strict Mode.  This mode applies global interval gating to
local widget updates; that is, in Strict Mode a player is not allowed to
toggle their personal UI settings -- such as colored *vs* monochrome nicknames --
more often than the global interval allows.  Prints the current value when used
without an argument.

#### leader [toggle]
```
/cplive leader toggle
/cplive leader
```
Enable or disable Leader Mode.  When this is turned on, the player with the
highest current checkpoint count (and the lowest time at their checkpoint) is
designated as the leader. The leader's checkpoint time is displayed as is;
while other drivers have their checkpoint times displayed as deltas from the
time that the leader had set at their respective checkpoints:
```
Checkpoints Live  Track CPs: 10  ×
-----------------------------------
  8  10:54.13  Player A             ← Leader
  6  +4:02.10  Player B             ← 4 minutes slower than A had been at CP 6
```
Prints the current value when used without an argument.

#### specs [toggle]
```
/cplive specs toggle
/cplive specs
```
This setting controls whether to include spectators among displayed players.
Prints the current value when used without an argument.

#### specmarker [toggle]
```
/cplive specmarker toggle
/cplive specmarker
```
Something must be shown in place of a checkpoint number when a spectator row is
rendered.  This setting toggles between displaying the eye icon[^eye] and
a text placeholder (`-` by default).  Prints the current value when used
without an argument.

[^eye]: The `Camera` substyle from
        `GameData\MenuForever\Media\Texture\Image\Icons64x64_1.dds`. For
        a convenient overview of the available substyle *names* rather than
        just a bare picture, see `tmtp:///:example`.

#### spectarget [toggle]
```
/cplive spectarget toggle
/cplive spectarget
```
Control whether to display the names of the players being spectated next to the
spectators themselves.  Additional "spectator targets" are by default the empty
string for the free camera mode, and `(auto)` for automatic targeting.[^auto]
Prints the current value when used without an argument.

[^auto]: A known bug is that clicking once to switch from the automatic
         targeting mode to the manual targeting mode does not update the
         displayed target.  This occurs because the vanilla TMNF/TMUF client
         does not, in fact, notify the server of this change.

#### disable
```
/cplive disable
```
This command completely stops any meaningful execution of the plug-in by
hot swapping the main class with a dummy one whose magic
[`__call()`](https://www.php.net/manual/en/language.oop5.overloading.php#object.call)
method keeps taking XAseco events but does nothing at all.  That is to say, the
widget does not simply become hidden but becomes entirely disabled.  This may be
useful as a way to quickly ensure that *Checkpoints Live v3* is not the source
of any observed XAseco lag.

#### enable
```
/cplive enable
```
Re-enable the widget.  A new class instance is created, and so the settings are
reset to the defaults.  (May be useful as a way to do just that.)

---
### Nerd commands

#### stats
```
/cplive stats
```
Display summary statistics accumulated during the widget's lifetime.  The
widget's lifetime begins when the widget is first shown (usually at the start
of a new challenge, but this also includes challenge restarts and other plug-in
reset events) and lasts until the widget is hidden globally (usually when the
podium is displayed at the end of a challenge).
```
.----------------------------------------------------------------------------.
| i CP Live v3.4.2 event statistics per widget lifetime:                     |
|----------------------------------------------------------------------------|
| Widget lifetime              4:05:15.15                                    |
|                                                                            |
| Total stats:                                                               |
|  Flushes                     238 (forced: 26, empty: 132)                  |
|  addCalls                    205 (0.01/s), no-CSL: 483 (0.03/s, 0 bundled) |
|  XML payloads                0.55 MiB, no-CSL: 1.39 MiB                    |
|  Spectator clicks            2                                             |
|  Hash hits                   SENTHASH=629 LISTHASHBIN=132                  |
|  Events (players)            CP=46 FIN=132 CONN=13 DISC=8                  |
|  Events (spectators)         MODE=17 TARGET=35                             |
|  Config touches              LOCAL=0 GLOBAL=0                              |
|                                                                            |
| addCall stats by variant:   real / no-CSL (bundled)                        |
|  spec (color)                106 / 286 (0)                                 |
|  driver (color)              99 / 197 (0)                                  |
`----------------------------------------------------------------------------`
                          Example pop-up window
```
| Row | Example output | Meaning |
| :--- | --- | :--- |
| Widget lifetime     | `4:05:15.15`                                    | The widget's current lifetime is 4 hours, 5 minutes, and 15.15 seconds.
| Flushes             | `238 (forced: 26, empty: 132)`                  | 238 global widget redraws were attempted when the global throttler expired *and* a redraw was pending because of some event that triggered it; of which 26 bypassed the global interval throttler, and 132 did not result in any network dispatch because the new widget state remained identical to what it was before the triggering event.
| addCalls            | `205 (0.01/s), no-CSL: 483 (0.03/s, 0 bundled)` | 205 total `addCall` invocations were recorded, at the average rate of 0.01/s in the course of the widget's current lifetime.  Additionally, 483 is the hypothetical number of `addCall` invocations that would have happened if the logins had not been joined into a comma-separated list per each XML payload type (e.g. `driver (color)`, `collapsed`, `spec (plain)`).  The latter is a useful proxy for the data that the dedicated server broadcasts to the connected players per the plug-in's instructions.
| XML payloads        | `0.55 MiB, no-CSL: 1.39 MiB`                    | The size of the XML data that is ultimately rendered as the Checkpoints Live widget by a TrackMania client, in [mebibytes](https://en.wikipedia.org/wiki/Byte#Multiple-byte_units).  As above, the no-CSL estimate is a useful proxy.
| Spectator clicks    | `2`                                             | A driver row was clicked twice by at least one spectator, *and* a `ForceSpectatorTarget` call was subsequently dispatched.  Clicks that do not result in a spectator target switch also do not normally increment this value (for example, when the spectator clicks their current target).
| Hash hits           | `SENTHASH=629 LISTHASHBIN=132`                  | There was a pending update 629 times but each player's last XML payload was byte-level identical to what would have been dispatched.  In addition, the displayed row view was marked as potentially dirty and in need of a re-render 132 times, but the hash of the underlying state -- CP times, the order of the displayed players, &c -- ended up identical to the prior state's, and so no payload was built.  The latter occurs when, for instance, an unlisted player disconnects and the list remains the same, or when a spectator becomes a driver and then a spectator again within one global interval tick.
| Events (players)    | `CP=46 FIN=132 CONN=13 DISC=8`                  | How many `onCheckpoint`, `onPlayerFinish`, `onPlayerConnect`, and `onPlayerDisconnect` events occurred during the widget's lifetime.  Note that `onPlayerFinish` events weirdly [include](https://www.undef.name/Development/Events.php#DescriptionXAseco1) resets to the start as well as actual finish events.
| Events (spectators) | `MODE=17 TARGET=35`                             | A spectator became a driver (or *vice versa*) 17 times; spectator target changes (including to/from the free camera mode and to/from the automatic target mode) were registered 35 times.
| Config touches      | `LOCAL=1 GLOBAL=2`                              | A player changed their local UI settings once; a MasterAdmin changed a global setting twice.

---
## License & contributing

This project is licensed under the GNU Affero General Public License, version
3 or (at your option) any later version.  See [LICENSE](LICENSE).  By
submitting a contribution, you agree that your contribution may be distributed
under the project's current license and any future license chosen by the
copyright holders.

## Copyright

Copyright © 2026 [potato](https://github.com/join-red) and
[Falleos](https://github.com/Falleos).
