<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

/**
 * The terminal's shell integration for zsh and bash, as Ghostty injects
 * its own, written for myphp: the shell marks its prompts and commands
 * with OSC 133 (A where the prompt starts, C as a command runs, D and its
 * exit code as it ends), which fish and Nushell do of themselves, so that
 * the terminal tells of commands that finished (Options::$notifyOnCommandFinish).
 * The user's own startup files are read as the shell would read them.
 *
 * zsh reads its .zshenv from $ZDOTDIR, pointed at a directory of the
 * integration's, whose .zshenv puts ZDOTDIR back, reads the user's and
 * adds its hooks; bash starts in POSIX mode, where an interactive shell
 * reads $ENV, the integration's script, which leaves POSIX mode, reads the
 * user's startup files as bash would and adds its hooks. Only a shell
 * started alone, as the user's login shell is, takes it: what arguments
 * ask of a shell is left to it.
 *
 * @internal
 */
final class ShellIntegration
{
    private const ZSHENV = <<<'ZSH'
    # myphp's shell integration for zsh (ZDOTDIR pointed here for this file alone).
    if [[ -n "${MYPHP_ZDOTDIR+x}" ]]; then
      ZDOTDIR=$MYPHP_ZDOTDIR
      builtin unset MYPHP_ZDOTDIR
    else
      builtin unset ZDOTDIR
    fi
    {
      [[ -f "${ZDOTDIR-$HOME}/.zshenv" ]] && builtin source "${ZDOTDIR-$HOME}/.zshenv"
    } always {
      if [[ -o interactive ]]; then
        typeset -g _myphp_running=
        _myphp_precmd() {
          local ret=$?
          if [[ -n $_myphp_running ]]; then
            builtin printf '\e]133;D;%s\a' $ret
            _myphp_running=
          fi
          builtin printf '\e]133;A\a'
        }
        _myphp_preexec() {
          builtin printf '\e]133;C\a'
          _myphp_running=1
        }
        builtin autoload -Uz add-zsh-hook
        add-zsh-hook precmd _myphp_precmd
        add-zsh-hook preexec _myphp_preexec
      fi
    }
    ZSH;

    private const BASH = <<<'BASH'
    # myphp's shell integration for bash, read as $ENV by a bash started in POSIX mode.
    if [[ "$-" != *i* ]]; then builtin return; fi
    if [[ -n "$MYPHP_BASH_INJECT" ]]; then
      builtin unset ENV MYPHP_BASH_INJECT
      if [[ -n "${MYPHP_BASH_ENV+x}" ]]; then
        builtin export ENV="$MYPHP_BASH_ENV"
        builtin unset MYPHP_BASH_ENV
      fi
      builtin set +o posix
      # The startup files bash reads, as it would have read them (INVOCATION in bash(1)).
      if builtin shopt -q login_shell; then
        [[ -r /etc/profile ]] && builtin source /etc/profile
        for _myphp_rc in "$HOME/.bash_profile" "$HOME/.bash_login" "$HOME/.profile"; do
          if [[ -r "$_myphp_rc" ]]; then builtin source "$_myphp_rc"; break; fi
        done
        builtin unset _myphp_rc
      elif [[ -r "$HOME/.bashrc" ]]; then
        builtin source "$HOME/.bashrc"
      fi
    fi
    _myphp_running=
    _myphp_at_prompt=
    _myphp_prompt() {
      local ret=$?
      if [[ -n $_myphp_running ]]; then
        builtin printf '\e]133;D;%s\a' "$ret"
        _myphp_running=
      fi
      builtin printf '\e]133;A\a'
      _myphp_at_prompt=1
      return $ret
    }
    _myphp_preexec() {
      if [[ -n $_myphp_at_prompt && $BASH_COMMAND != _myphp_prompt* ]]; then
        _myphp_at_prompt=
        _myphp_running=1
        builtin printf '\e]133;C\a'
      fi
    }
    PROMPT_COMMAND="_myphp_prompt${PROMPT_COMMAND:+;$PROMPT_COMMAND}"
    # A command's start is the DEBUG trap's, unless the user set one of their own.
    if [[ -z "$(builtin trap -p DEBUG)" ]]; then builtin trap '_myphp_preexec' DEBUG; fi
    BASH;

    /**
     * The program $path, run with $args and the environment $env ("K=V"
     * each), as the integration starts it: [args, env], with its own when
     * $path is zsh or bash started alone, else as they are.
     * @param list<string> $args
     * @param list<string> $env
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function apply(string $path, array $args, array $env): array
    {
        $shell = basename($path);
        if (count($args) !== 1 || ($shell !== 'zsh' && $shell !== 'bash')) {
            return [$args, $env];
        }
        $vars = [];
        foreach ($env as $kv) { $vars[explode('=', $kv, 2)[0]] = $kv; }
        $value = fn(string $k): ?string => isset($vars[$k]) ? explode('=', $vars[$k], 2)[1] : null;
        $dir = self::files();
        if ($shell === 'zsh') {
            if (($z = $value('ZDOTDIR')) !== null) { $vars['MYPHP_ZDOTDIR'] = "MYPHP_ZDOTDIR=$z"; }
            $vars['ZDOTDIR'] = "ZDOTDIR=$dir/zsh";
            return [$args, array_values($vars)];
        }
        if (($e = $value('ENV')) !== null) { $vars['MYPHP_BASH_ENV'] = "MYPHP_BASH_ENV=$e"; }
        $vars['ENV'] = "ENV=$dir/bash/myphp.bash";
        $vars['MYPHP_BASH_INJECT'] = 'MYPHP_BASH_INJECT=1';
        return [[$args[0], '--posix'], array_values($vars)];
    }

    /** The directory of the integration's files, written once in the system's temporary directory, by their contents. */
    private static function files(): string
    {
        $dir = sys_get_temp_dir() . '/myphp-shell-integration-' . substr(sha1(self::ZSHENV . self::BASH), 0, 12);
        if (!is_file("$dir/bash/myphp.bash")) {
            foreach (['zsh/.zshenv' => self::ZSHENV, 'bash/myphp.bash' => self::BASH] as $file => $text) {
                @mkdir(dirname("$dir/$file"), 0755, true);
                $tmp = "$dir/$file." . getmypid();
                if (file_put_contents($tmp, $text . "\n") === false || !rename($tmp, "$dir/$file")) {
                    throw new \RuntimeException("terminal: the shell integration could not be written to $dir");
                }
            }
        }
        return $dir;
    }
}
