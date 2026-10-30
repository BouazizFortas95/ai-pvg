// engram OpenCode plugin
// Injects a knowledge graph reminder before bash tool calls when the graph exists.
import { existsSync } from "fs";
import { join } from "path";

export const GraphifyPlugin = async ({ directory }) => {
  let reminded = false;

  return {
    "tool.execute.before": async (input, output) => {
      if (reminded) return;
      if (!existsSync(join(directory, ".engram", "graph.json")) && !existsSync(join(directory, ".graphify", "graph.json"))) return;

      if (input.tool === "bash") {
        // Separate with ';' not '&&' — Windows PowerShell 5.1 rejects '&&' as a
        // statement separator, which broke the first bash command of every
        // OpenCode session on Windows. ';' works in PowerShell 5.1, Bash, and
        // POSIX shells alike. Port of upstream 54825b6 (#1646).
        output.args.command =
          'echo "[engram] Knowledge graph at .engram/. For focused questions, run engram query \"<question>\" (scoped subgraph, usually much smaller than GRAPH_REPORT.md) instead of grepping raw files. Read GRAPH_REPORT.md only for broad architecture context." ; ' +
          output.args.command;
        reminded = true;
      }
    },
  };
};
