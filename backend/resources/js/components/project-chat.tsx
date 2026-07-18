import { useMemo } from "react";
import { ChatRoom, type ChatEndpoints } from "@/components/chat-room";
import { api } from "@/lib/api-client";

/**
 * Per-case chat room — a thin binding of the shared <ChatRoom> to the
 * project chat API. Access, participants and channel come from the server.
 */
export function ProjectChat({ projectId }: { projectId: number | string }) {
  const endpoints: ChatEndpoints = useMemo(() => ({
    load: () => api.getProjectChat(projectId),
    send: (payload) => api.sendProjectChat(projectId, payload),
    edit: (id, content) => api.editProjectChatMessage(projectId, id, content),
    remove: (id) => api.deleteProjectChatMessage(projectId, id).then(() => undefined),
    markRead: (id) => api.markProjectChatRead(projectId, id).then(() => undefined),
    downloadAttachment: (path, name) => api.downloadChatAttachment(projectId, path, name),
  }), [projectId]);

  return (
    <ChatRoom
      endpoints={endpoints}
      roomKey={`project-${projectId}`}
      title="Case Discussion"
      placeholder="Message the case team…  (@ to mention)"
      emptyText="No messages yet. Start the discussion for this matter."
      forbiddenText="Only people assigned to this matter can view its chat."
    />
  );
}
