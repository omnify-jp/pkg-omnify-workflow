import { createContext, useContext } from 'react';
import type { ComponentType, ReactNode } from 'react';

export type WorkflowBreadcrumbItem = {
    title: string;
    href: string;
};

/**
 * The shape that a host app's layout component must satisfy to be used by Workflow pages.
 */
export type WorkflowLayoutComponent = ComponentType<{
    children: ReactNode;
    breadcrumbs?: WorkflowBreadcrumbItem[];
}>;

function PassthroughLayout({ children }: { children: ReactNode; breadcrumbs?: WorkflowBreadcrumbItem[] }) {
    return <>{children}</>;
}

/**
 * Provides the host app's layout component to Workflow pages.
 *
 * Wrap your app (in app.tsx) with this context and pass your AppLayout:
 *   <WorkflowLayoutContext.Provider value={AppLayout}>
 *     <App {...props} />
 *   </WorkflowLayoutContext.Provider>
 */
export const WorkflowLayoutContext = createContext<WorkflowLayoutComponent>(PassthroughLayout);

export function useWorkflowLayout(): WorkflowLayoutComponent {
    return useContext(WorkflowLayoutContext);
}
