import { useState } from "react";
import { Tabs } from "../../components/ui";
import OrganizationTab from "./OrganizationTab";

const TABS = [
    { key: "departments", label: "Departemen" },
    { key: "positions", label: "Jabatan" },
];

export default function Organization() {
    const [tab, setTab] = useState("departments");
    const current = TABS.find((t) => t.key === tab);

    return (
        <div className="space-y-4">
            <Tabs tabs={TABS} value={tab} onChange={setTab} />
            <OrganizationTab
                key={tab}
                resource={current.key}
                label={current.label}
            />
        </div>
    );
}
