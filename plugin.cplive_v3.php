<?php
/**
 * SPDX-FileCopyrightText: 2026 potato and Falleos
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 *
 * Checkpoints Live v3
 * Copyright © 2026:
 *    - potato                        (thinking-medium)
 *    - Falleos                       (fast)
 *
 * Project repository: https://github.com/join-red/checkpoints_live
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or (at your
 * option) any later version.  By submitting a contribution, you agree that
 * your contribution may be distributed under the project's current license and
 * any future license chosen by the copyright holders.
 * 
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU Affero General Public License
 * for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Additionally, potato feels compelled to credit these models with ideas for
 * further optimizations, PHP idioms, and error-checking potato's code:
 *    - claude-opus-4.6               (thinking)
 *    - gpt-5.2                       (high)
 *    - gemini-3.1-pro-preview        (thinking-high)
 *    - gpt-5.4                       (high)
 *
 * This program was rearchitected from scratch as a successor to CP Live v2
 * (2011) by b4card1 & Lambda.  The screen position, dimensions, and action
 * ID constants of the widget were kept to make it a drop-in replacement.
 *
 * If the Records Eyepiece plugin is enabled, it is highly recommended also
 * to enable its 'nice mode' feature when the server is experiencing a high
 * level of activity:
 *    /eyeset forcenice true
 *
 * Tested on PHP v7.3 running under GNU/Linux.
 **/

class CPLive {
    public const VERSION = '3.4.3';

    // Configuration
    public $MAX_DISPLAY_ROWS = 24;            // How many rows (drivers/spectators) may be shown

    public $POS_X = -64.4;                    // Where on the screen the widget should be rendered
    public $POS_Y =  22.7;                    // Ditto

    public $WIDGET_UPDATE_INTERVAL = 700;     // How often a redraw is allowed, in milliseconds
    public $MIN_WIDGET_UPDATE_INTERVAL = 50;  // Disallow admins from setting the interval lower
    public $STRICT_MODE = false;              // Strict mode applies interval gating to local updates
    public $LEADER_MODE = false;              // Format checkpoint times as offsets from the leader

    public $ALLOW_NICK_STYLE_TOGGLE = true;   // Allow local toggling between colored/plain nicknames
    public $PLAIN_NICKS = false;              // Default nickname style

    public $SHOW_SPECTATORS = true;           // Include spectators in the rendered list
    public $USE_SPECTATOR_ICON = true;        // Eye icon instead of text marker for spectators
    public $SHOW_SPECTATOR_TARGETS = true;    // Show nicknames of spectator targets

    // UI & Action Constants
    public const COLORS = [
        'Title'      => 'FFE',
        'TrackText'  => 'ABC',
        'TrackCPs'   => '9B1',
        'CPNumber'   => 'F93',
        'Time'       => 'FFC',
        'Mono'       => 'ABC',
        'MonoSystem' => '333',
        'DeltaPos'   => 'FAA',
        'DeltaNeg'   => 'AAF'
    ];

    public const FALLBACK_DRIVER_TIME_STR    = '--.--';  // Format the unknown 0.00 "time" as this string
    public const FALLBACK_SPECTATOR_TIME_STR = '';       // Fallback string for a spectator's time slot
    public const SPECTATOR_CP_PLACEHOLDER    = '-';      // Text marker to show in place of a spectator's checkpoint number

    public const ID_TITLE_BAR = 1928378;
    public const ID_LIST      = 1928379;

    public const ANSWER_TOGGLE_HUD    = '01928390';
    public const ANSWER_SWITCH_COLOR  = '01928396';
    public const ANSWER_SPECTATE_BASE = '71928400';

    // Note that this action key will conflict with those shared by other
    // plugins.  Often F7 is the default action key used to hide and show
    // Records Eyepiece widgets, while F5 and F6 are commonly bound to
    // chat-based Y/N votes. Therefore this feature is off by default; but
    // you have the option to choose your personal lesser evil.
    public const TOGGLE_KEY = 0; // 0 = None | 1 = F5 | 2 = F6 | 3 = F7
    public const KEYS = ['', 'F5', 'F6', 'F7'];

    private const CFG_TOUCH_NONE    = 0;
    private const CFG_TOUCH_SLICE   = 1;
    private const CFG_TOUCH_ROWS    = 2;
    private const CFG_TOUCH_PAYLOAD = 4;

    // Keep track of the data and views touched by each setting. The
    // setConfig() and toggleConfig() methods rely on these specs to
    // trigger the minimum required refresh when a setting changes.
    private const CONFIG_TOUCH = [
        // May alter which players are shown and in what order
        'MAX_DISPLAY_ROWS'           => self::CFG_TOUCH_SLICE,
        'SHOW_SPECTATORS'            => self::CFG_TOUCH_SLICE,

        // The top-N slice is unchanged but the row contents may differ
        'LEADER_MODE'                => self::CFG_TOUCH_ROWS,
        'USE_SPECTATOR_ICON'         => self::CFG_TOUCH_ROWS,
        'SHOW_SPECTATOR_TARGETS'     => self::CFG_TOUCH_ROWS,

        // May affect XML bytes but not the row semantics or top-N slice
        'ALLOW_NICK_STYLE_TOGGLE'    => self::CFG_TOUCH_PAYLOAD,
        'TOGGLE_KEY'                 => self::CFG_TOUCH_PAYLOAD,
        'POS_X'                      => self::CFG_TOUCH_PAYLOAD,
        'POS_Y'                      => self::CFG_TOUCH_PAYLOAD,

        // Settings that don't affect the bytes of any XML payload
        'STRICT_MODE'                => self::CFG_TOUCH_NONE,
        'WIDGET_UPDATE_INTERVAL'     => self::CFG_TOUCH_NONE,
        'MIN_WIDGET_UPDATE_INTERVAL' => self::CFG_TOUCH_NONE,
    ];

    private $aseco;

    private $shouldRender;      // Should the widget be displayed? Not during intermission/podium
    private $players;           // Everyone on the server: login → player state + XML dispatch state
    private $specActionToLogin; // Action ID of clickable quad → associated login to spectate
    private $pidToLogin;        // Player's ID → their login, used for spectator target lookup
    private $totalCPs;          // Total checkpoints including the finish
    private $leaderLogin;       // Current leading driver (highest CP, lowest time at that CP)
    private $challengeSeed;     // CRC32 of current challenge's UID, used for challenge-local spectator ordering

    // Shown list state
    private $dirtyMask;         // Which layers need rebuilding
    private $shownLogins;       // Ordered top-N slice
    private $listRows;          // Semantic row model of the top-N slice
    private $listHashBin;       // Hash of the shared row model, used to invalidate the payload cache

    // Scheduler state
    private $lastGlobalUpdate;  // Timestamp of the last dispatch that consumed the global throttler
    private $globalDueAt;       // Earliest time a pending shared payload is allowed to leave

    // Static / shared XML
    private $payloadCache;             // Cached XML payloads. Variant key => ['xml' => ..., 'hash' => ...]
    private $xmlTitleBar;              // Cached title bar per track, expanded
    private $xmlTitleBarCollapsed;     // Static title bar, collapsed
    private $xmlEmpty;                 // Static empty maniapage used during intermission
    private $xmlTitleBarCollapsedHash; // md5(xmlTitleBarCollapsed)
    private $xmlEmptyHash;             // md5(xmlEmpty)

    private $stats;                    // Track CP Live statistics per widget lifetime

    // Dirty flags for dirtyMask oooh
    private const DIRTY_SLICE = 1; // Which players are shown, and in what order
    private const DIRTY_ROWS  = 2; // Row contents of the shown slice

    // Leftmost slot: checkpoint / finish / spectator marker
    private const CP_CELL_NUMBER    = 0;
    private const CP_CELL_FINISH    = 1;
    private const CP_CELL_SPEC_ICON = 2;
    private const CP_CELL_SPEC_TEXT = 3;

    // Middle slot: time / delta / spectator target text
    private const TIME_CELL_DRIVER_UNKNOWN = 0;
    private const TIME_CELL_DRIVER_TIME    = 1;
    private const TIME_CELL_DRIVER_DELTA   = 2;
    private const TIME_CELL_SPEC_FALLBACK  = 3;
    private const TIME_CELL_SPEC_EMPTY     = 4;
    private const TIME_CELL_SPEC_AUTO      = 5;
    private const TIME_CELL_SPEC_TARGET    = 6;


    public function init($aseco) {
        $this->aseco = $aseco;

        $this->sendChatMessage('Started CP Live v' . self::VERSION);
        $this->stats = new CPLiveStats();

        $this->players = [];
        $this->pidToLogin = [];
        $this->specActionToLogin = [];

        $this->totalCPs = 0;
        $this->challengeSeed = 0;
        $this->globalDueAt = null;

        $this->cacheStaticXml();
        $this->updateTrackInfo();
        $this->reset(true);
    }


    public function reset($hydrateSpecStatus = false) {
        foreach ($this->aseco->server->players->player_list as $player) {
            $login = $player->login;

            if (!isset($this->players[$login])) {
                $this->initPlayer($player);
            }

            $this->resetPlayerTime($login);

            // When CP Live is disabled and re-enabled, spectator target info
            // is lost. Take the one-time performance hit of requesting every
            // player's PlayerInfo without waiting for an event.
            if ($hydrateSpecStatus) {
                $this->syncSpectatorState($login);
            } else {
                $this->players[$login]['Spectator'] = $player->isspectator;
            }

            $this->players[$login]['TieKey'] = crc32($login) ^ $this->challengeSeed;
            $this->players[$login]['LastUpdate'] = 0;
            $this->players[$login]['PendingGlobal'] = false;
            $this->players[$login]['PendingLocal'] = false;
            $this->players[$login]['LocalDueAt'] = null;
        }

        $this->shouldRender = true;
        $this->leaderLogin = null;
        $this->shownLogins = [];
        $this->listRows = [];
        $this->listHashBin = '';
        $this->payloadCache = [];
        $this->lastGlobalUpdate = 0;

        $this->dirtyMask = self::DIRTY_SLICE | self::DIRTY_ROWS;

        $this->stats->reset();
        $this->scheduleGlobalDelivery(true);
        $this->flush(true);
    }


    public function initPlayer($player_item) {
        $login = $player_item->login;
        $pid = $player_item->pid;
        $nickname = htmlspecialchars($player_item->nickname ?? '');

        // Offset the base by the player's ID to create a clickable quad & spectate this player.
        // PIDs range from 1 to 250 (0xFC for autotargets): collisions with other XAseco plugins
        // are unlikely.
        $spectateAction = sprintf('%08d', ((int) self::ANSWER_SPECTATE_BASE) + (int) $pid);

        $this->players[$login] = [
            'Login'              => $login,
            'Pid'                => $pid,

            'PlainNicks'         => $this->PLAIN_NICKS,
            'Collapsed'          => false,

            'NicknamePlainXml'   => '$' . self::COLORS['Mono'] . stripColors(self::stripSizes($nickname)),
            'NicknameColoredXml' => self::stripSizes($nickname),

            'CPNumber'           => 0,
            'RawTime'            => 0,
            'CPTimes'            => [0],

            'Spectator'          => $player_item->isspectator,
            'SpectatorStatus'    => null,
            'SpectatesPid'       => 0,
            'AutoTarget'         => false,
            'SpectateAction'     => $spectateAction,
            'TieKey'             => crc32($login) ^ $this->challengeSeed,

            'SentHash'           => '',    // Exact bytes we believe the player currently sees
            'LastUpdate'         => 0,     // Last time we sent them any XML payload
            'PendingGlobal'      => false, // Stale because of a shared view change
            'PendingLocal'       => false, // Stale because of their own local UI action
            'LocalDueAt'         => null   // Earliest time that a local update may leave
        ];

        $this->specActionToLogin[$spectateAction] = $login;
        $this->pidToLogin[$pid] = $login;
    }


    public function resetPlayerTime($login) {
        $p = &$this->players[$login];

        $p['CPNumber'] = 0;
        $p['RawTime'] = 0;
        $p['CPTimes'] = [0];

        unset($p);
    }


    public function handlePlayerConnect($player_item) {
        $this->stats->connects++;
        $this->initPlayer($player_item);

        $this->payloadCache = [];

        $this->requestSliceRefresh();
        $this->requestLocalRefresh($player_item->login, true);
        $this->flush();
    }


    public function handlePlayerDisconnect($player_item) {
        $this->stats->disconnects++;
        $login = $player_item->login;

        unset($this->specActionToLogin[$this->players[$login]['SpectateAction']]);
        unset($this->pidToLogin[$this->players[$login]['Pid']]);
        unset($this->players[$login]);

        $this->payloadCache = [];

        $this->requestSliceRefresh();
        $this->flush(true);
    }


    public function handlePlayerCheckpoint($checkpoint) {
        $this->stats->checkpoints++;
        $login = $checkpoint[1];
        $cpIdx = $checkpoint[4] + 1;

        $this->players[$login]['CPNumber']        = $cpIdx;
        $this->players[$login]['RawTime']         = $checkpoint[2];
        $this->players[$login]['CPTimes'][$cpIdx] = $checkpoint[2];

        $this->requestSliceRefresh();
        $this->flush();
    }


    public function handlePlayerFinish($finish) {
        $this->stats->finishes++;
        $login = $finish->player->login;

        if ($finish->score === 0 || $this->players[$login]['CPNumber'] !== $this->totalCPs) {
            $this->resetPlayerTime($login);
        }

        $this->requestSliceRefresh();
        $this->flush();
    }


    public function handlePlayerInfoChanged($playerinfo) {
        $login = $playerinfo['Login'];
        $player = &$this->players[$login];
        $specStatus = $playerinfo['SpectatorStatus'];
        $player['SpectatorStatus'] = $specStatus;
        $spec = (($specStatus % 10) != 0);

        $targetChanged = false;
        $autoChanged = false;

        if ($spec) {
            $targetPid = intdiv($specStatus, 10000);
            $autoTarget = ((intdiv($specStatus, 1000) % 10) !== 0);

            $targetChanged = ($player['SpectatesPid'] !== $targetPid);
            $autoChanged   = ($player['AutoTarget']   !== $autoTarget);

            if ($targetChanged || $autoChanged) {
                $this->stats->targetChanges++;
            }

            $player['SpectatesPid'] = $targetPid;
            $player['AutoTarget'] = $autoTarget;
        } else {
            $targetChanged = ($player['SpectatesPid'] !== 0);
            $autoChanged   = ($player['AutoTarget']   !== false);

            $player['SpectatesPid'] = 0;
            $player['AutoTarget'] = false;
        }

        if ($player['Spectator'] !== $spec) {
            $this->stats->specChanges++;
            $player['Spectator'] = $spec;
            $this->resetPlayerTime($login);

            unset($player);

            $this->requestSliceRefresh();
            $this->requestLocalRefresh($login, true);
            $this->flush(true);
            return;
        }

        unset($player);

        if ($this->SHOW_SPECTATORS && $this->SHOW_SPECTATOR_TARGETS) {
            if (($targetChanged || $autoChanged) && $this->isLoginPossiblyShown($login)) {
                $this->requestRowRefresh();
                $this->flush();
            }
        }
    }


    public function handlePlayerAnswer($answer) {
        $login = $answer[1];
        $answerStr = sprintf('%08d', (int) $answer[2]);

        if ($answerStr === self::ANSWER_TOGGLE_HUD) {
            $this->togglePlayerUiFlag($login, 'Collapsed');
            return;
        }

        if ($answerStr === self::ANSWER_SWITCH_COLOR) {
            $this->togglePlayerUiFlag($login, 'PlainNicks');
            return;
        }

        if (!isset($this->specActionToLogin[$answerStr]) || !$this->players[$login]['Spectator']) {
            return;
        }

        $desiredTargetLogin = $this->specActionToLogin[$answerStr];

        if ($this->players[$desiredTargetLogin]['Spectator'] || $desiredTargetLogin === $login) {
            return;
        }

        $spectatesPid = $this->players[$login]['SpectatesPid'];
        $currentTarget = $this->pidToLogin[$spectatesPid];

        if ($desiredTargetLogin === $currentTarget) {
            return;
        }

        $this->stats->targetForces++;
        $this->aseco->client->queryIgnoreResult('ForceSpectatorTarget', $login, $desiredTargetLogin, -1);
    }


    public function handleChatCommands($command) {
        $login = $command['author']->login;
        $args = self::parseCommandParams($command['params']);
        $cmd = $args[0];

        $adminCmds = ['refresh', 'strict', 'rows', 'leader', 'specs', 'specmarker', 'spectarget'];

        if (in_array($cmd, $adminCmds, true) && !$this->aseco->isMasterAdminL($login)) {
            $this->sendChatMessage('$f00You don\'t have the required admin rights to do that!', $login);
            return;
        }

        switch ($cmd) {
        case 'color':
            $this->togglePlayerUiFlag($login, 'PlainNicks');
            break;

        case 'toggle':
            $this->togglePlayerUiFlag($login, 'Collapsed');
            break;

        case 'strict':
            if (!isset($args[1])) {
                $this->sendChatMessage('CP Live strict mode is ' . ($this->STRICT_MODE ? 'enabled' : 'disabled'), $login);
                break;
            }

            $this->toggleConfig('STRICT_MODE');
            $this->sendChatMessage('CP Live strict mode has been ' . ($this->STRICT_MODE ? 'enabled' : 'disabled'));
            break;

        case 'leader':
            if (!isset($args[1])) {
                $this->sendChatMessage('CP Live leader mode is ' . ($this->LEADER_MODE ? 'enabled' : 'disabled'), $login);
                break;
            }

            $this->toggleConfig('LEADER_MODE');
            $this->sendChatMessage('CP Live leader mode has been ' . ($this->LEADER_MODE ? 'enabled' : 'disabled'));
            $this->flush(true);
            break;

        case 'refresh':
            if (!isset($args[1]) || filter_var($args[1], FILTER_VALIDATE_INT) === false) {
                $this->sendChatMessage('Current CP Live update interval is ' . $this->WIDGET_UPDATE_INTERVAL . ' ms', $login);
                break;
            }

            $newVal = max($this->MIN_WIDGET_UPDATE_INTERVAL, min(3600000, (int) $args[1]));
            $this->sendChatMessage('CP Live update interval has been changed from ' . $this->WIDGET_UPDATE_INTERVAL . ' ms to ' . $newVal . ' ms');
            $this->setConfig('WIDGET_UPDATE_INTERVAL', $newVal);
            break;

        case 'rows':
            if (!isset($args[1]) || filter_var($args[1], FILTER_VALIDATE_INT) === false) {
                $this->sendChatMessage('Current CP Live number of rows is ' . $this->MAX_DISPLAY_ROWS . ' row' . ($this->MAX_DISPLAY_ROWS > 1 ? 's' : ''), $login);
                break;
            }

            $newVal = max(1, min(50, (int) $args[1]));
            $this->sendChatMessage('CP Live number of rows has been changed from ' . $this->MAX_DISPLAY_ROWS . ' row' . ($this->MAX_DISPLAY_ROWS > 1 ? 's' : '') . ' to ' . $newVal  . ' row' . ($newVal > 1 ? 's' : ''));
            $this->setConfig('MAX_DISPLAY_ROWS', $newVal);
            $this->flush(true);
            break;

        case 'specs':
            if (!isset($args[1])) {
                $this->sendChatMessage('CP Live spectators are now ' . ($this->SHOW_SPECTATORS ? 'shown' : 'hidden'), $login);
                break;
            }

            $this->toggleConfig('SHOW_SPECTATORS');
            $this->sendChatMessage('CP Live spectators are now ' . ($this->SHOW_SPECTATORS ? 'shown' : 'hidden'));
            $this->flush(true);
            break;

        case 'specmarker':
            if (!isset($args[1])) {
                $this->sendChatMessage('CP Live spectator eye icon is ' . ($this->USE_SPECTATOR_ICON ? 'enabled' : 'disabled'), $login);
                break;
            }

            $this->toggleConfig('USE_SPECTATOR_ICON');
            $this->sendChatMessage('CP Live spectator eye icon has been ' . ($this->USE_SPECTATOR_ICON ? 'enabled' : 'disabled'));
            $this->flush(true);
            break;

        case 'spectarget':
            if (!isset($args[1])) {
                $this->sendChatMessage('CP Live spectator targets are ' . ($this->SHOW_SPECTATOR_TARGETS ? 'shown' : 'hidden'), $login);
                break;
            }

            $this->toggleConfig('SHOW_SPECTATOR_TARGETS');
            $this->sendChatMessage('CP Live spectator targets are now ' . ($this->SHOW_SPECTATOR_TARGETS ? 'shown' : 'hidden'));
            $this->flush(true);
            break;

        case 'stats':
            $this->stats->displayStats($login);
            break;

        default:
            $this->showHelpManialink($login);
            break;
        }
    }


    public function showHelpManialink($login) {
        $header = '{#black}CP Live v' . self::VERSION . '$g overview:';

        $help   = [];

        $help[] = ['$sUser commands:', '', ''];
        $help[] = ['color', '', 'Toggle between colored and plain nicknames'];
        $help[] = ['toggle', '{#black}' . self::KEYS[self::TOGGLE_KEY], 'Collapse or expand the Checkpoints Live widget'];
        $help[] = [];

        $help[] = ['$sMasterAdmin commands:', '', ''];
        $help[] = ['refresh',          '{#black}[<int>]',  'Minimum global widget update interval (ms)'];
        $help[] = ['rows',             '{#black}[<int>]',  'Maximum number of players shown'];
        $help[] = ['strict',           '{#black}[toggle]', 'Strict throttling:  enforce per-player rate limit'];
        $help[] = ['leader',           '{#black}[toggle]', 'Track checkpoint differences to leading driver'];
        $help[] = ['specs',            '{#black}[toggle]', 'Include spectators in shown players'];
        $help[] = ['specmarker',       '{#black}[toggle]', 'Spectator symbol style:  icon $ivs$i  text marker'];
        $help[] = ['spectarget',       '{#black}[toggle]', 'Display nicknames of spectator targets'];
        $help[] = ['(enable|disable)', '',                 'Stop and resume all plugin execution'];
        $help[] = [];

        $help[] = ['$sNerd commands:', '', ''];
        $help[] = ['stats', '', 'View performance stats (per widget lifetime)'];
        $help[] = [];
        $help[] = ['$sCredits:', '', ''];
        $help[] = ['{#black}poैtato & Falleos', '', '$0bf$l[http://github.com/join-red/checkpoints_live]Project repository on GitHub$l'];
        $help[] = [];

        display_manialink($login, $header, ['Icons64x64_1', 'TrackInfo', -0.01], $help, [1.4, 0.38, 0.17, 0.85], 'OK');
    }


    public function updateTrackInfo() {
        $this->aseco->client->query('GetCurrentChallengeInfo');
        $info = $this->aseco->client->getResponse();

        $this->totalCPs = $info['NbCheckpoints'];
        $this->challengeSeed = crc32($info['UId']);

        if ($this->updateTitleBarXml() && $this->shouldRender) {
            $this->requestPayloadRefresh();
        }
    }


    public function formatTime($milliseconds, $isDelta = false) {
        $prefix = '';

        if ($isDelta) {
            if ($milliseconds < 0) {
                $prefix = '$' . self::COLORS['DeltaNeg'] . '-';
            } elseif ($milliseconds > 0) {
                $prefix = '$' . self::COLORS['DeltaPos'] . '+';
            } else {
                $prefix = '$' . self::COLORS['DeltaNeg'];
            }

            $milliseconds = abs($milliseconds);
        }

        $totalSeconds = (int) ($milliseconds / 1000);
        $cs = (int) (($milliseconds % 1000) / 10); // Centiseconds

        $h = (int) ($totalSeconds / 3600);
        $m = (int) (($totalSeconds % 3600) / 60);
        $s = $totalSeconds % 60;

        if ($h > 0) {
            return sprintf('%s%d:%02d:%02d.%02d', $prefix, $h, $m, $s, $cs);
        }

        if ($m > 0) {
            return sprintf('%s%d:%02d.%02d', $prefix, $m, $s, $cs);
        }

        return sprintf('%s%02d.%02d', $prefix, $s, $cs);
    }


    public function destroyWidgetUI() {
        if (!$this->shouldRender) {
            return;
        }

        $this->shouldRender = false;
        $this->dirtyMask = 0;
        $this->globalDueAt = null;
        $this->payloadCache = [];

        foreach ($this->players as &$p) {
            $p['PendingGlobal'] = false;
            $p['PendingLocal'] = false;
            $p['LocalDueAt'] = null;
        }
        unset($p);

        $now = $this->getMilliSeconds();

        $this->sendManialink($this->xmlEmpty, null, 1);

        foreach ($this->players as &$p) {
            $p['SentHash'] = $this->xmlEmptyHash;
            $p['LastUpdate'] = $now;
        }
        unset($p);
    }


    public function sendUI($targetLogin = '', $forceNow = false) {
        if ($targetLogin === '') {
            $this->flush($forceNow);
            return;
        }

        $this->requestLocalRefresh($targetLogin, $forceNow);
        $this->flush($forceNow);
    }


    public static function parseCommandParams($params) {
        $params = trim(strtolower($params));

        if ($params === '') {
            return [''];
        }

        return preg_split('/\s+/', $params, 2);
    }


    // The function from basic.inc.php doesn't strip italic formatting; roll
    // our own.
    public static function stripSizes($nick) {
        $placeholder = "\0";

        $nick = str_replace('$$', $placeholder, $nick);
        $nick = preg_replace('/\$(?:[nwoi]|$)/iu', '', $nick);
        $nick = str_replace($placeholder, '$$', $nick);

        return $nick;
    }


    private function togglePlayerUiFlag($login, $key) {
        $this->stats->localToggles++;
        $this->players[$login][$key] = !$this->players[$login][$key];
        $this->requestLocalRefresh($login);
        $this->flush();
    }


    private function setConfig($key, $value) {
        if ($this->$key === $value) {
            return false;
        }

        $this->stats->configChanges++;
        $this->$key = $value;

        // Assume that the rows are touched by default if our config spec
        // has no such setting.
        $touch = self::CONFIG_TOUCH[$key] ?? self::CFG_TOUCH_ROWS;

        // Bitwise AND
        if ($touch & self::CFG_TOUCH_SLICE) {
            $this->requestSliceRefresh();
        } elseif ($touch & self::CFG_TOUCH_ROWS) {
            $this->requestRowRefresh();
        } elseif ($touch & self::CFG_TOUCH_PAYLOAD) {
            $this->requestPayloadRefresh();
        }

        if ($key === 'STRICT_MODE' || $key === 'WIDGET_UPDATE_INTERVAL') {
            $this->reschedulePendingLocals();
        }

        if ($key === 'WIDGET_UPDATE_INTERVAL') {
            $this->rescheduleGlobalDueAt();
        }

        return true;
    }


    private function toggleConfig($key) {
        return $this->setConfig($key, !$this->$key);
    }


    /**
     * A change happened that may alter which players are shown or in what
     * order. Rebuild the shown top-N slice, then the rows.
     */
    private function requestSliceRefresh() {
        $this->dirtyMask |= self::DIRTY_SLICE | self::DIRTY_ROWS;
        $this->scheduleGlobalDelivery(false);
    }


    /**
     * The shown top-N slice is unchanged, but the row semantics may differ:
     * spectator targets, leader deltas, driver ↔ spectator transitions that
     * stay within the shown slice, or the spectator marker.
     */
    private function requestRowRefresh() {
        $this->dirtyMask |= self::DIRTY_ROWS;
        $this->scheduleGlobalDelivery(false);
    }


    /**
     * Shared payload bytes changed without changing the row model, for
     * example when a payload variant was enabled.
     */
    private function requestPayloadRefresh() {
        $this->payloadCache = [];
        $this->scheduleGlobalDelivery(false);
    }


    private function scheduleGlobalDelivery($includeCollapsed) {
        // Collapsed viewers catch up when they expand. Reset/start is the
        // exception because everybody is coming back from the empty page.
        foreach ($this->players as &$p) {
            $p['PendingGlobal'] = ($includeCollapsed || !$p['Collapsed']);
        }
        unset($p);

        $this->rescheduleGlobalDueAt();
    }


    /**
     * Queue a local-only viewer update. LocalDueAt is that viewer's earliest
     * legal send time under STRICT_MODE. With STRICT_MODE off it becomes "now."
     */
    private function requestLocalRefresh($login, $forceNow = false) {
        $p = &$this->players[$login];
        $p['PendingLocal'] = true;

        if ($forceNow || !$this->STRICT_MODE) {
            $dueAt = $this->getMilliSeconds();
        } else {
            $latest = max($this->lastGlobalUpdate, $p['LastUpdate']);
            $dueAt = max($this->getMilliSeconds(), $latest + $this->WIDGET_UPDATE_INTERVAL);
        }

        if ($p['LocalDueAt'] === null || $dueAt < $p['LocalDueAt']) {
            $p['LocalDueAt'] = $dueAt;
        }

        unset($p);
    }


    private function reschedulePendingLocals() {
        $now = $this->getMilliSeconds();

        foreach ($this->players as &$p) {
            if (!$p['PendingLocal']) {
                continue;
            }

            if (!$this->STRICT_MODE) {
                $p['LocalDueAt'] = $now;
                continue;
            }

            $latest = max($this->lastGlobalUpdate, $p['LastUpdate']);
            $p['LocalDueAt'] = max($now, $latest + $this->WIDGET_UPDATE_INTERVAL);
        }
        unset($p);
    }


    private function flush($forceNow = false) {
        if (!$this->shouldRender) {
            return;
        }

        $now = $this->getMilliSeconds();

        if (!$forceNow && !$this->isFlushDue($now)) {
            return;
        }

        $this->stats->totalFlushes++;

        if ($forceNow) {
            $this->stats->forcedFlushes++;
        }

        $this->ensureRowsUpToDate();

        // Dispatch algorithm:
        //   1. Build the desired payload for every viewer.
        //   2. Drop viewers already showing those exact bytes.
        //   3. Among stale viewers, take only the ones whose deadline is due.
        //   4. Group them by desired payload hash.
        //   5. If a hash is already leaving, bundle every other stale viewer
        //      wanting those same bytes. (Free in addCall terms when we use
        //      comma-separated logins, since bundling it still creates just
        //      the one addCall.)
        $desired = [];

        foreach ($this->players as $login => $player) {
            $variantKey = $this->getVariantKey($player);
            $payload = $this->getPayload($variantKey);
            $desired[$login] = $payload + ['variantKey' => $variantKey];

            $matched = ($payload['hash'] === $player['SentHash']);
            $hadPending = ($player['PendingGlobal'] || $player['PendingLocal']);

            if ($player['PendingGlobal'] && $matched) {
                $this->players[$login]['PendingGlobal'] = false;
            }

            if ($player['PendingLocal'] && $matched) {
                $this->players[$login]['PendingLocal'] = false;
                $this->players[$login]['LocalDueAt'] = null;
            }

            if ($hadPending && $matched) {
                $this->stats->hashHits++;
            }
        }

        $groups = [];
        $consumedGlobal = false;

        foreach ($this->players as $login => $player) {
            $desiredHash = $desired[$login]['hash'];

            if ($desiredHash === $player['SentHash']) {
                continue;
            }

            $dueGlobal = $player['PendingGlobal'] && (
                $forceNow || (
                    $this->globalDueAt !== null &&
                    $now >= $this->globalDueAt &&
                    $now >= ($player['LastUpdate'] + $this->WIDGET_UPDATE_INTERVAL)
                )
            );

            $dueLocal = $player['PendingLocal'] && (
                $forceNow || (
                    $player['LocalDueAt'] !== null &&
                    $now >= $player['LocalDueAt']
                )
            );

            if (!$dueGlobal && !$dueLocal) {
                continue;
            }

            if (!isset($groups[$desiredHash])) {
                $groups[$desiredHash] = [
                    'xml'           => $desired[$login]['xml'],
                    'xmlBytes'      => strlen($desired[$login]['xml']),
                    'variantKeys'   => [],
                    'variantCounts' => [],
                    'bundleCounts'  => [],
                    'logins'        => []
                ];
            }

            $groups[$desiredHash]['logins'][$login] = true;

            $variantKey = $desired[$login]['variantKey'];
            $groups[$desiredHash]['variantKeys'][$variantKey] = true;
            $groups[$desiredHash]['variantCounts'][$variantKey] = ($groups[$desiredHash]['variantCounts'][$variantKey] ?? 0) + 1;

            if ($dueGlobal) {
                $consumedGlobal = true;
            }
        }

        if (empty($groups)) {
            $this->stats->emptyFlushes++;
            $this->rescheduleGlobalDueAt();
            return;
        }

        foreach ($this->players as $login => $player) {
            $desiredHash = $desired[$login]['hash'];

            if ($desiredHash === $player['SentHash'] || !isset($groups[$desiredHash])) {
                continue;
            }

            // Avoid double-counting due players as bundled players
            if (!isset($groups[$desiredHash]['logins'][$login])) {
                $groups[$desiredHash]['logins'][$login] = true;

                $variantKey = $desired[$login]['variantKey'];
                $groups[$desiredHash]['variantKeys'][$variantKey] = true;
                $groups[$desiredHash]['variantCounts'][$variantKey] = ($groups[$desiredHash]['variantCounts'][$variantKey] ?? 0) + 1;
                $groups[$desiredHash]['bundleCounts'][$variantKey] = ($groups[$desiredHash]['bundleCounts'][$variantKey] ?? 0) + 1;
            }
        }

        foreach ($groups as $hash => $group) {
            $logins = array_keys($group['logins']);

            if (empty($logins)) {
                continue;
            }

            $variantKeys = array_keys($group['variantKeys']);
            $physicalVariantKey = (count($variantKeys) === 1) ? $variantKeys[0] : 'mixed';

            $this->stats->noteDispatch(
                $physicalVariantKey,
                $group['variantCounts'],
                $group['bundleCounts'],
                $group['xmlBytes']
            );

            $this->sendManialink($group['xml'], implode(',', $logins));

            foreach ($logins as $login) {
                $this->players[$login]['SentHash'] = $hash;
                $this->players[$login]['LastUpdate'] = $now;
                $this->players[$login]['PendingGlobal'] = false;
                $this->players[$login]['PendingLocal'] = false;
                $this->players[$login]['LocalDueAt'] = null;
            }
        }

        if ($consumedGlobal) {
            $this->lastGlobalUpdate = $now;
        }

        $this->rescheduleGlobalDueAt();
    }


    private function isFlushDue($now) {
        if ($this->globalDueAt !== null && $now >= $this->globalDueAt) {
            return true;
        }

        foreach ($this->players as $player) {
            if ($player['PendingLocal'] && $player['LocalDueAt'] !== null && $now >= $player['LocalDueAt']) {
                return true;
            }
        }

        return false;
    }


    /**
     * globalDueAt is the earliest legal time any pending shared redraw may
     * leave. It's constrained by both the global throttler and each player's
     * LastUpdate: a fresh local send should delay the next shared overwrite
     * for that player too.
     */
    private function rescheduleGlobalDueAt() {
        $this->globalDueAt = null;

        foreach ($this->players as $player) {
            if (!$player['PendingGlobal']) {
                continue;
            }

            // Take the earliest ...
            $dueAt = max(
                $this->lastGlobalUpdate + $this->WIDGET_UPDATE_INTERVAL,
                $player['LastUpdate'] + $this->WIDGET_UPDATE_INTERVAL
            );

            // ... most eligible player. Effectively, we take the minimum over
            // all players who can legally receive an update.
            if ($this->globalDueAt === null || $dueAt < $this->globalDueAt) {
                $this->globalDueAt = $dueAt;
            }
        }
    }


    private function ensureRowsUpToDate() {
        if (($this->dirtyMask & self::DIRTY_SLICE) !== 0) {
            $this->rebuildShownSlice();
        }

        if (($this->dirtyMask & self::DIRTY_ROWS) === 0) {
            return;
        }

        $rows = [];
        $leaderTimes = null;

        if ($this->LEADER_MODE && $this->leaderLogin !== null) {
            $leaderTimes = $this->players[$this->leaderLogin]['CPTimes'];
        }

        $ctx = hash_init('md5');

        foreach ($this->shownLogins as $login) {
            $row = $this->buildRowView($login, $leaderTimes);
            $rows[] = $row;
            $this->hashRowModel($ctx, $row);
        }

        $newHashBin = hash_final($ctx, true);

        $this->listRows = $rows;

        if ($newHashBin !== $this->listHashBin) {
            $this->listHashBin = $newHashBin;
            $this->payloadCache = [];
        } else {
            $this->stats->rowModelHashHits++;
        }

        $this->dirtyMask &= ~self::DIRTY_ROWS;
    }


    private function rebuildShownSlice() {
        $logins = [];

        foreach ($this->players as $login => $player) {
            if (!$player['Spectator'] || $this->SHOW_SPECTATORS) {
                $logins[] = $login;
            }
        }

        if (!empty($logins)) {
            usort($logins, function ($a, $b) {
                $p1 = $this->players[$a];
                $p2 = $this->players[$b];

                // Spectators below everyone else
                if ($this->SHOW_SPECTATORS && $p1['Spectator'] !== $p2['Spectator']) {
                    return $p1['Spectator'] <=> $p2['Spectator'];
                }

                if ($p1['CPNumber'] !== $p2['CPNumber']) {
                    return $p2['CPNumber'] <=> $p1['CPNumber'];
                }

                if ($p1['RawTime'] !== $p2['RawTime']) {
                    return $p1['RawTime'] <=> $p2['RawTime'];
                }

                // Before the final login tiebreaker, attempt to break ties by
                // tie keys computed from the current challenge's UID and each
                // player's login. Otherwise on a busy server some spectators
                // would always be pushed out of the visible slice.
                if ($p1['TieKey'] !== $p2['TieKey']) {
                    return $p1['TieKey'] <=> $p2['TieKey'];
                }

                // Stable tie-breaker by login
                return strcmp($a, $b);
            });

            $logins = array_slice($logins, 0, $this->MAX_DISPLAY_ROWS);
        }

        $this->shownLogins = $logins;
        $this->leaderLogin = null;

        foreach ($logins as $login) {
            $player = $this->players[$login];

            // By definition of the sort above, the first driver with
            // a non-zero checkpoint is the leader.
            if (!$player['Spectator'] && $player['CPNumber'] > 0) {
                $this->leaderLogin = $login;
                break;
            }
        }

        $this->dirtyMask &= ~self::DIRTY_SLICE;
    }


    private function buildRowView($login, $leaderTimes) {
        $player = $this->players[$login];
        $cp = (int) $player['CPNumber'];

        // Default values used for hashing. The formatting in buildListXml
        // should rely on the enums rather than these values.
        $cpCellValue = 0;
        $timeCellValue = '';

        if ($cp === $this->totalCPs) {
            $cpCellKind = self::CP_CELL_FINISH;
        } elseif ($player['Spectator']) {
            $cpCellKind = $this->USE_SPECTATOR_ICON ? self::CP_CELL_SPEC_ICON : self::CP_CELL_SPEC_TEXT;
        } else {
            $cpCellKind = self::CP_CELL_NUMBER;
            $cpCellValue = $cp;
        }

        if ($player['Spectator']) {
            if (!$this->SHOW_SPECTATOR_TARGETS) {
                $timeCellKind = self::TIME_CELL_SPEC_FALLBACK;
            } elseif ($player['AutoTarget']) {
                $timeCellKind = self::TIME_CELL_SPEC_AUTO;
            } elseif ($player['SpectatesPid'] > 0 && isset($this->pidToLogin[$player['SpectatesPid']])) {
                $targetLogin = $this->pidToLogin[$player['SpectatesPid']];
                $timeCellKind = self::TIME_CELL_SPEC_TARGET;
                $timeCellValue = $this->players[$targetLogin]['NicknamePlainXml'];
            } else {
                $timeCellKind = self::TIME_CELL_SPEC_EMPTY;
            }
        } elseif ($cp === 0) {
            $timeCellKind = self::TIME_CELL_DRIVER_UNKNOWN;
        } elseif ($this->LEADER_MODE && $this->leaderLogin !== null && $login !== $this->leaderLogin && $leaderTimes !== null && isset($leaderTimes[$cp])) {
            $timeCellKind = self::TIME_CELL_DRIVER_DELTA;
            $timeCellValue = $player['RawTime'] - $leaderTimes[$cp];
        } else {
            $timeCellKind = self::TIME_CELL_DRIVER_TIME;
            $timeCellValue = $player['RawTime'];
        }

        return [
            'Login'         => $login,
            'IsSpectator'   => $player['Spectator'],
            'CpCellKind'    => $cpCellKind,
            'CpCellValue'   => $cpCellValue,
            'TimeCellKind'  => $timeCellKind,
            'TimeCellValue' => $timeCellValue
        ];
    }


    private function hashRowModel($ctx, $row) {
        hash_update(
            $ctx,
            $row['Login'] . "\0" .
            ($row['IsSpectator'] ? "1\0" : "0\0") .
            $row['CpCellKind'] . "\0" .
            $row['CpCellValue'] . "\0" .
            $row['TimeCellKind'] . "\0" .
            $row['TimeCellValue'] . "\0"
        );
    }


    private function getVariantKey($player) {
        if ($player['Collapsed']) {
            return 'c';
        }

        $plain = ($this->ALLOW_NICK_STYLE_TOGGLE && $player['PlainNicks']) ? 1 : 0;
        $spec = $player['Spectator'] ? 2 : 0;

        return $plain | $spec;
    }


    private function getPayload($variantKey) {
        if ($variantKey === 'c') {
            return [
                'xml'  => $this->xmlTitleBarCollapsed,
                'hash' => $this->xmlTitleBarCollapsedHash
            ];
        }

        if (isset($this->payloadCache[$variantKey])) {
            return $this->payloadCache[$variantKey];
        }

        $plain = (($variantKey & 1) !== 0);
        $spec  = (($variantKey & 2) !== 0);

        $xml = '<manialinks>' . $this->xmlTitleBar . $this->buildListXml($plain, $spec) . '</manialinks>';

        $this->payloadCache[$variantKey] = [
            'xml'  => $xml,
            'hash' => md5($xml)
        ];

        return $this->payloadCache[$variantKey];
    }


    public function buildListXml($viewerPrefersPlain, $viewerIsSpectator) {
        $hud = '<manialink id="' . self::ID_LIST . '">';
        $y = $this->POS_Y - 1.9;

        foreach ($this->listRows as $row) {
            $login = $row['Login'];
            $player = $this->players[$login];

            $hud .= '<frame posn="' . $this->POS_X . ' ' . $y . '">';

            if ($viewerIsSpectator && !$row['IsSpectator']) {
                $hud .= '<quad posn="0 0 -0.5" sizen="21 1.8" halign="left" valign="center" style="Bgs1InRace" substyle="NavButton" action="' . $player['SpectateAction'] . '"/>';
            }

            switch ($row['CpCellKind']) {
            case self::CP_CELL_FINISH:
                $hud .= '<quad posn="3.02 0 0.06" sizen="1.6 1.6" halign="right" valign="center" style="BgRaceScore2" substyle="Warmup"/>';
                break;

            case self::CP_CELL_SPEC_ICON:
                $hud .= '<quad posn="1.98 0 0.06" sizen="1.2 1.2" halign="left" valign="center" style="Icons64x64_1" substyle="Camera"/>';
                break;

            case self::CP_CELL_SPEC_TEXT:
                $hud .= '<label scale="0.48" posn="3 0.1 0.1" halign="right" valign="center" style="TextRaceMessage" text="$' . self::COLORS['CPNumber'] . self::SPECTATOR_CP_PLACEHOLDER . '"/>';
                break;

            default:
                $hud .= '<label scale="0.48" posn="3 0.1 0.1" halign="right" valign="center" style="TextRaceMessage" text="$' . self::COLORS['CPNumber'] . $row['CpCellValue'] . '"/>';
                break;
            }

            switch ($row['TimeCellKind']) {
            case self::TIME_CELL_DRIVER_UNKNOWN:
                $hud .= '<label scale="0.48" posn="8.5 0.15 0.1" sizen="10.9 2" halign="right" valign="center" style="TextRaceMessage" text="$' . self::COLORS['Time'] . self::FALLBACK_DRIVER_TIME_STR . '"/>';
                break;

            case self::TIME_CELL_DRIVER_DELTA:
                $hud .= '<label scale="0.48" posn="8.5 0.1 0.1" sizen="10.9 2" halign="right" valign="center" style="TextRaceMessage" text="' . $this->formatTime($row['TimeCellValue'], true) . '"/>';
                break;

            case self::TIME_CELL_DRIVER_TIME:
                $hud .= '<label scale="0.48" posn="8.5 0.1 0.1" sizen="10.9 2" halign="right" valign="center" style="TextRaceMessage" text="$' . self::COLORS['Time'] . $this->formatTime($row['TimeCellValue']) . '"/>';
                break;

            case self::TIME_CELL_SPEC_FALLBACK:
                $hud .= '<label scale="0.48" posn="8.5 0.1 0.1" sizen="10.9 2" halign="right" valign="center" style="TextRaceMessage" text="$' . self::COLORS['Time'] . self::FALLBACK_SPECTATOR_TIME_STR . '"/>';
                break;

            case self::TIME_CELL_SPEC_EMPTY:
                break;

            case self::TIME_CELL_SPEC_AUTO:
                $hud .= '<label scale="0.35" posn="8.5 -0.02 0.1" sizen="10.9 2" halign="right" valign="center" style="TextRaceMessage" text="$' . self::COLORS['MonoSystem'] . '(auto)"/>';
                break;

            default:
                $hud .= '<label scale="0.35" posn="8.5 -0.02 0.1" sizen="10.9 2" halign="right" valign="center" style="TextRaceMessage" text="' . $row['TimeCellValue'] . '"/>';
                break;
            }

            $nickname = $viewerPrefersPlain ? $player['NicknamePlainXml'] : $player['NicknameColoredXml'];
            $hud .= '<label scale="0.48" posn="8.8 0.1 0.1" sizen="24.6 2" halign="left" valign="center" style="TextRaceMessage" text="' . $nickname . '"/>';

            $hud .= '</frame>';

            $y -= 1.8;
        }

        $hud .= '</manialink>';

        return $hud;
    }


    public function cacheStaticXml() {
        $this->xmlTitleBarCollapsed  = '<manialinks>';
        $this->xmlTitleBarCollapsed .= '<manialink id="' . self::ID_TITLE_BAR . '">';
        $this->xmlTitleBarCollapsed .= '<frame posn="' . $this->POS_X . ' ' . $this->POS_Y . '">';
        $this->xmlTitleBarCollapsed .= '<quad posn="0 0 -10" sizen="0 0" action="' . self::ANSWER_TOGGLE_HUD . '" actionkey="' . self::TOGGLE_KEY . '"/>';
        $this->xmlTitleBarCollapsed .= '<quad posn="0 0 0" sizen="6.8 2" halign="left" valign="center" style="BgsPlayerCard" substyle="BgCard"/>';
        $this->xmlTitleBarCollapsed .= '<label scale="0.45" posn="0.4 0.1 0.1" halign="left" valign="center" style="TextRaceMessage" text="$' . self::COLORS['Title'] . ' CP Live"/>';
        $this->xmlTitleBarCollapsed .= '<quad posn="4.9 0 0.12" sizen="1.8 1.8" halign="left" valign="center" style="Icons64x64_1" substyle="Camera" action="' . self::ANSWER_TOGGLE_HUD . '"/>';
        $this->xmlTitleBarCollapsed .= '</frame>';
        $this->xmlTitleBarCollapsed .= '</manialink>';
        $this->xmlTitleBarCollapsed .= '<manialink id="' . self::ID_LIST . '" />';
        $this->xmlTitleBarCollapsed .= '</manialinks>';

        $this->xmlEmpty  = '<manialinks>';
        $this->xmlEmpty .= '<manialink id="' . self::ID_TITLE_BAR . '" />';
        $this->xmlEmpty .= '<manialink id="' . self::ID_LIST . '" />';
        $this->xmlEmpty .= '</manialinks>';

        $this->xmlTitleBarCollapsedHash = md5($this->xmlTitleBarCollapsed);
        $this->xmlEmptyHash = md5($this->xmlEmpty);
    }


    public function updateTitleBarXml() {
        $trackCPs = max(0, $this->totalCPs - 1);

        $hud  = '<manialink id="' . self::ID_TITLE_BAR . '">';
        $hud .= '<frame posn="' . $this->POS_X . ' ' . $this->POS_Y . '">';
        $hud .= '<quad posn="0 0 -10" sizen="0 0" action="' . self::ANSWER_TOGGLE_HUD . '" actionkey="' . self::TOGGLE_KEY . '"/>';
        $hud .= '<quad posn="0 0 0" sizen="21 2" halign="left" valign="center" style="BgsPlayerCard" substyle="BgCard" action="' . self::ANSWER_SWITCH_COLOR . '"/>';
        $hud .= '<label scale="0.45" posn="0.4 0.1 0.1" halign="left" valign="center" style="TextRaceMessage" text="$' . self::COLORS['Title'] . ' Checkpoints Live"/>';
        $hud .= '<label scale="0.45" posn="10.5 0.1 0.1" halign="left" valign="center" style="TextRaceMessage" text="$' . self::COLORS['TrackText'] . 'Track CPs:"/>';
        $hud .= '<label scale="0.45" posn="16.25 0.1 0.1" halign="left" valign="center" style="TextRaceMessage" text="$' . self::COLORS['TrackCPs'] . $trackCPs . '"/>';
        $hud .= '<quad posn="19 0 0.12" sizen="1.8 1.8" halign="left" valign="center" style="Icons64x64_1" substyle="Close" action="' . self::ANSWER_TOGGLE_HUD . '"/>';
        $hud .= '</frame>';
        $hud .= '</manialink>';

        if ($hud === $this->xmlTitleBar) {
            return false;
        }

        $this->xmlTitleBar = $hud;
        return true;
    }


    private function syncSpectatorState($login) {
        $this->aseco->client->query('GetPlayerInfo', $login, 1);
        $info = $this->aseco->client->getResponse();

        $specStatus = $info['SpectatorStatus'];
        $spec = (($specStatus % 10) != 0);

        $this->players[$login]['SpectatorStatus'] = $specStatus;
        $this->players[$login]['Spectator'] = $spec;
        $this->players[$login]['AutoTarget'] = $spec && ((intdiv($specStatus, 1000) % 10) !== 0);
        $this->players[$login]['SpectatesPid'] = intdiv($specStatus, 10000);
    }


    public function sendChatMessage($msg, $login = null) {
        if ($login) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', '$ff0> $fff' . $this->aseco->formatColors($msg), $login);
            return;
        }

        $this->aseco->client->query('ChatSendServerMessage', '$ff0>> $fff' . $this->aseco->formatColors($msg));
    }


    private function isLoginPossiblyShown($login) {
        if (($this->dirtyMask & self::DIRTY_SLICE) !== 0) {
            return true;
        }

        return in_array($login, $this->shownLogins, true);
    }


    public function getMilliSeconds() {
        return (int) (microtime(true) * 1000);
    }


    private function sendManialink($xml, $logins = null, $timeout = 0, $hide = false) {
        if ($logins !== null && $logins !== '') {
            $this->aseco->client->addCall('SendDisplayManialinkPageToLogin', [$logins, $xml, $timeout, $hide]);
            return;
        }

        $this->aseco->client->addCall('SendDisplayManialinkPage', [$xml, $timeout, $hide]);
    }
}


class CPLiveDisabled {
    public function __call($name, $arguments) {}
}


class CPLiveStats {
    // Track the addCalls that actually happened
    //       collapsed | spec × plain | byte dedupe
    // Keys: 'c'       | 0, 1, 2, 3   | 'mixed'
    public $addCallsByVariant = [];

    public $addCallsNoCSLByVariant = []; // Hypothetical addCalls if comma-separated logins weren't used
    public $bundledByVariant = [];   // ... of those logins, how many were added to an already-due hash group

    public $hashHits = 0;                // Desired payload already matched SentHash
    public $rowModelHashHits = 0;        // Row model hash unchanged, payload cache kept

    public $totalAddCalls = 0;
    public $totalAddCallsNoCSL = 0;
    public $totalBundled = 0;
    public $totalXmlBytes = 0;
    public $totalXmlBytesNoCSL = 0;

    public $totalFlushes = 0;
    public $forcedFlushes = 0;
    public $emptyFlushes = 0;

    public $checkpoints = 0;
    public $finishes = 0;
    public $connects = 0;
    public $disconnects = 0;
    public $specChanges = 0;
    public $targetChanges = 0;
    public $localToggles = 0;
    public $configChanges = 0;
    public $targetForces = 0;

    public $widgetResetAt = 0;


    public function reset() {
        $this->addCallsByVariant = [];
        $this->addCallsNoCSLByVariant = [];
        $this->bundledByVariant = [];

        $this->totalAddCalls = 0;
        $this->totalAddCallsNoCSL = 0;
        $this->totalBundled = 0;
        $this->totalXmlBytes = 0;
        $this->totalXmlBytesNoCSL = 0;

        $this->totalFlushes = 0;
        $this->forcedFlushes = 0;
        $this->emptyFlushes = 0;

        $this->hashHits = 0;
        $this->rowModelHashHits = 0;

        $this->checkpoints = 0;
        $this->finishes = 0;
        $this->connects = 0;
        $this->disconnects = 0;
        $this->specChanges = 0;
        $this->targetChanges = 0;
        $this->localToggles = 0;
        $this->configChanges = 0;
        $this->targetForces = 0;

        $this->widgetResetAt = CPLive::getMilliSeconds();
    }


    public function currentWidgetLifetime() {
        $now = CPLive::getMilliSeconds();
        $elapsed = $now - $this->widgetResetAt;

        return (int) $elapsed;
    }


    public function noteDispatch($physicalVariantKey, $variantCounts, $bundleCounts, $xmlBytes) {
        $recipientCount = 0;
        $bundleCount = 0;

        foreach ($variantCounts as $variantKey => $count) {
            $recipientCount += $count;
            $this->addCallsNoCSLByVariant[$variantKey] = ($this->addCallsNoCSLByVariant[$variantKey] ?? 0) + $count;
        }

        foreach ($bundleCounts as $variantKey => $count) {
            $bundleCount += $count;
            $this->bundledByVariant[$variantKey] = ($this->bundledByVariant[$variantKey] ?? 0) + $count;
        }

        $this->totalAddCalls++;
        $this->totalAddCallsNoCSL += $recipientCount;
        $this->totalBundled += $bundleCount;
        $this->totalXmlBytes += $xmlBytes;
        $this->totalXmlBytesNoCSL += ($xmlBytes * $recipientCount);

        $this->addCallsByVariant[$physicalVariantKey] = ($this->addCallsByVariant[$physicalVariantKey] ?? 0) + 1;
    }


    public function displayStats($login) {
        $elapsedMs = $this->currentWidgetLifetime();
        $elapsedSec = $elapsedMs / 1000;
        $callRate = round($this->totalAddCalls / $elapsedSec, 2);
        $callRateNoCSL = round($this->totalAddCallsNoCSL / $elapsedSec, 2);

        $header = '{#black}CP Live v' . CPLive::VERSION . '$g event statistics per widget lifetime:';

        $out   = [];

        $out[] = ['{#black}Widget lifetime',     CPLive::formatTime($elapsedMs)];
        $out[] = [];
        $out[] = ['$sTotal stats:', ''];
        $out[] = ['{#black}Flushes',             $this->totalFlushes . '  (forced:  ' . $this->forcedFlushes . ', empty:  ' . $this->emptyFlushes . ')'];
        $out[] = ['{#black}addCalls',            $this->totalAddCalls . '  (' . $callRate . '/s),  no-CSL:  ' . $this->totalAddCallsNoCSL . '  (' . $callRateNoCSL . '/s, ' . $this->totalBundled . ' bundled)'];
        $out[] = ['{#black}XML payloads',        round($this->totalXmlBytes / (1024 * 1024), 2) . ' MiB, no-CSL:  ' . round($this->totalXmlBytesNoCSL / (1024 * 1024), 2) . ' MiB'];
        $out[] = ['{#black}Spectator clicks',    $this->targetForces];
        $out[] = ['{#black}Hash hits',           'SENTHASH=' . $this->hashHits . '   LISTHASHBIN=' . $this->rowModelHashHits];
        $out[] = ['{#black}Events (players)',    'CP=' . $this->checkpoints . '   FIN=' . $this->finishes . '   CONN=' . $this->connects . '   DISC=' . $this->disconnects];
        $out[] = ['{#black}Events (spectators)', 'MODE=' . $this->specChanges . '   TARGET=' . $this->targetChanges];
        $out[] = ['{#black}Config touches',      'LOCAL=' . $this->localToggles . '   GLOBAL=' . $this->configChanges];
        $out[] = [];

        $out[] = ['$saddCall stats by variant:', '$sreal  / no-CSL (bundled)'];

        foreach ($this->addCallsByVariant as $key => $count) {
            $label = $this->variantLabel($key);
            $n = $this->addCallsNoCSLByVariant[$key] ?? 0;
            $p = $this->bundledByVariant[$key] ?? 0;

            if ($key === 'mixed') {
                $out[] = ['{#black}' . $label, $count];
            } else {
                $out[] = ['{#black}' . $label, $count . ' / ' . $n . ' (' . $p . ')'];
            }
        }

        display_manialink($login, $header, ['Icons64x64_1', 'TrackInfo', -0.01], $out, [1.4, 0.42, 0.98], 'OK');
    }


    private function variantLabel($key) {
        if ($key === 'c') {
            return 'collapsed';
        }

        if ($key === 'mixed') {
            return 'mixed';
        }

        $plain = (($key & 1) !== 0);
        $spec  = (($key & 2) !== 0);

        return ($spec ? 'spec' : 'driver') . ' (' . ($plain ? 'plain' : 'color') . ')';
    }
}


global $cpLive;
$cpLive = new CPLive();

Aseco::registerEvent('onSync', 'cpLive_onSync');
function cpLive_onSync($aseco) { global $cpLive; $cpLive->init($aseco); }

Aseco::registerEvent('onEndRace', 'cpLive_onEndRace');
function cpLive_onEndRace($aseco) { global $cpLive; $cpLive->destroyWidgetUI(); }

Aseco::registerEvent('onPlayerConnect', 'cpLive_onPlayerConnect');
function cpLive_onPlayerConnect($aseco, $player) { global $cpLive; $cpLive->handlePlayerConnect($player); }

Aseco::registerEvent('onCheckpoint', 'cpLive_onCheckpoint');
function cpLive_onCheckpoint($aseco, $checkpoint) { global $cpLive; $cpLive->handlePlayerCheckpoint($checkpoint); }

Aseco::registerEvent('onPlayerFinish', 'cpLive_onPlayerFinish');
function cpLive_onPlayerFinish($aseco, $finish) { global $cpLive; $cpLive->handlePlayerFinish($finish); }

Aseco::registerEvent('onPlayerManialinkPageAnswer', 'cpLive_onPlayerManialinkPageAnswer');
function cpLive_onPlayerManialinkPageAnswer($aseco, $answer) { global $cpLive; $cpLive->handlePlayerAnswer($answer); }

Aseco::registerEvent('onPlayerInfoChanged', 'cpLive_onPlayerInfoChanged');
function cpLive_onPlayerInfoChanged($aseco, $playerinfo) { global $cpLive; $cpLive->handlePlayerInfoChanged($playerinfo); }

Aseco::registerEvent('onRestartChallenge', 'cpLive_onRestartChallenge');
function cpLive_onRestartChallenge($aseco, $challenge) { global $cpLive; $cpLive->reset(); }

Aseco::registerEvent('onNewChallenge', 'cpLive_onNewChallenge');
function cpLive_onNewChallenge($aseco, $challenge) { global $cpLive; $cpLive->updateTrackInfo(); }

Aseco::registerEvent('onBeginRound', 'cpLive_onBeginRound');
function cpLive_onBeginRound($aseco) { global $cpLive; $cpLive->reset(); }

Aseco::registerEvent('onPlayerDisconnect', 'cpLive_onPlayerDisconnect');
function cpLive_onPlayerDisconnect($aseco, $player) { global $cpLive; $cpLive->handlePlayerDisconnect($player); }

Aseco::registerEvent('onEverySecond', 'cpLive_onEverySecond');
function cpLive_onEverySecond($aseco) { global $cpLive; $cpLive->sendUI(); }

Aseco::addChatCommand('cplive', 'Checkpoints Live v3: see "/cplive help"');
function chat_cpLive($aseco, $command) {
    global $cpLive;

    $login = $command['author']->login;
    $args = CPLive::parseCommandParams($command['params']);
    $cmd = $args[0];

    $isMasterAdmin = $aseco->isMasterAdminL($login);

    if ($cmd === 'disable' || $cmd === 'enable') {
        if (!$isMasterAdmin) {
            $aseco->client->query('ChatSendServerMessageToLogin', '$ff0> $f00You don\'t have the required admin rights to do that!', $login);
            return;
        }

        if ($cmd === 'disable') {
            if ($cpLive instanceof CPLive) {
                $cpLive->destroyWidgetUI();
                $aseco->client->query('ChatSendServerMessage', '$ff0>> $fffCP Live has been disabled');
                $cpLive = new CPLiveDisabled();
            } else {
                $aseco->client->query('ChatSendServerMessageToLogin', '$ff0> $fffCP Live is already disabled', $login);
            }
            return;
        }

        if ($cmd === 'enable') {
            if ($cpLive instanceof CPLiveDisabled) {
                $cpLive = new CPLive();
                $cpLive->init($aseco);
            } else {
                $aseco->client->query('ChatSendServerMessageToLogin', '$ff0> $fffCP Live is already enabled', $login);
            }
            return;
        }
    }

    if ($cpLive instanceof CPLiveDisabled) {
        $aseco->client->query('ChatSendServerMessageToLogin', '$ff0> $fffCP Live is disabled: a MasterAdmin must enable it', $login);
        return;
    }

    $cpLive->handleChatCommands($command);
}

?>
